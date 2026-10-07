<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCommandLog;
use App\Models\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCommandGatewayController extends ApiController
{
    public function sync(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $request->validate(['request_id' => ['required', 'uuid']]);

        $result = DB::transaction(function () use ($device): array {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $session = DeviceSession::query()->where('device_id', $lockedDevice->id)
                ->where('transport', 'HTTPS')->where('state', 'AUTHENTICATED')
                ->where('expires_at', '>', $now)->orderByDesc('authenticated_at')->lockForUpdate()->first();

            if (! $session) {
                DeviceSession::query()->where('device_id', $lockedDevice->id)->where('transport', 'HTTPS')
                    ->where('state', 'AUTHENTICATED')->update([
                        'state' => 'EXPIRED', 'disconnected_at' => $now, 'updated_at' => $now,
                    ]);
                $session = DeviceSession::query()->create([
                    'id' => (string) Str::uuid(),
                    'device_id' => $lockedDevice->id,
                    'session_generation' => (string) Str::uuid(),
                    'state' => 'AUTHENTICATED',
                    'transport' => 'HTTPS',
                    'authenticated_at' => $now,
                    'last_heartbeat_at' => $now,
                    'expires_at' => $now->copy()->addMinutes(15),
                ]);
            } else {
                $session->forceFill([
                    'last_heartbeat_at' => $now,
                    'expires_at' => $now->copy()->addMinutes(15),
                ])->save();
            }

            $candidate = DeviceCommand::query()->where('device_id', $lockedDevice->id)
                ->whereIn('status', ['PENDING', 'QUEUED', 'SENT'])
                ->where('expires_at', '>', $now)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('device_commands as earlier')
                        ->whereColumn('earlier.device_id', 'device_commands.device_id')
                        ->whereColumn('earlier.sequence', '<', 'device_commands.sequence')
                        ->whereNotIn('earlier.status', ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED']);
                })
                ->orderBy('sequence')->first();

            if (! $candidate) {
                return ['session_id' => $session->id, 'command' => null];
            }

            // Match the gateway's outbox -> command lock order before changing delivery state.
            $outbox = DB::table('transactional_outbox')->where('aggregate_type', 'device_command')
                ->where('aggregate_id', $candidate->id)->lockForUpdate()->first();
            $command = DeviceCommand::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $outbox || ! $command || $command->device_id !== $lockedDevice->id
                || ! in_array($command->status, ['PENDING', 'QUEUED', 'SENT'], true)
                || $command->expires_at->lte($now)) {
                return ['session_id' => $session->id, 'command' => null];
            }

            if ($command->status === 'PENDING') {
                $command->forceFill(['status' => 'QUEUED'])->save();
                $this->logCommand($command, 'device.command.queued', ['transport' => 'HTTPS'], 'GATEWAY');
            }
            if ($command->status !== 'SENT') {
                $command->forceFill(['status' => 'SENT', 'sent_at' => $now])->save();
            }
            DB::table('transactional_outbox')->where('id', $outbox->id)->update([
                'available_at' => $now->copy()->addSeconds(15),
                'claim_id' => null,
                'claimed_until' => null,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);
            $this->logCommand($command, 'device.command.https_sent', [
                'device_session_id' => $session->id,
                'transport' => 'HTTPS',
            ], 'GATEWAY');

            return [
                'session_id' => $session->id,
                'command' => [
                    'command_id' => $command->id,
                    'device_id' => $command->device_id,
                    'device_session_id' => $session->id,
                    'command_type' => $command->command_type,
                    'sequence' => $command->sequence,
                    'payload' => $command->payload,
                    'expires_at' => $command->expires_at->toISOString(),
                ],
            ];
        }, attempts: 3);

        return $this->success($result, 'Sinkronisasi HTTPS perangkat diproses.');
    }

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $now = now();
        $items = DB::transaction(function () use ($data, $now): array {
            $expiredCommandIds = DeviceCommand::query()
                ->where('expires_at', '<=', $now)
                ->whereNotIn('status', ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'])
                ->orderBy('expires_at')
                ->limit(100)
                ->pluck('id');
            foreach ($expiredCommandIds as $expiredCommandId) {
                $outbox = DB::table('transactional_outbox')->where('aggregate_type', 'device_command')
                    ->where('aggregate_id', $expiredCommandId)->lockForUpdate()->first();
                $expiredCommand = DeviceCommand::query()->whereKey($expiredCommandId)->lockForUpdate()->first();
                if (! $expiredCommand || ! $outbox || ! $expiredCommand->expires_at->lte($now)
                    || in_array($expiredCommand->status, ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'], true)) {
                    continue;
                }
                $this->expireCommand($expiredCommand, $now);
                DB::table('transactional_outbox')->where('id', $outbox->id)->update([
                    'published_at' => $now,
                    'claim_id' => null,
                    'claimed_until' => null,
                    'updated_at' => $now,
                ]);
            }

            $rows = DB::table('transactional_outbox as outbox')
                ->join('device_commands as command', 'command.id', '=', 'outbox.aggregate_id')
                ->where('outbox.aggregate_type', 'device_command')
                ->where('outbox.event', 'device.command.created')
                ->whereNull('outbox.published_at')
                ->where('outbox.available_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('outbox.claimed_until')->orWhere('outbox.claimed_until', '<=', $now))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('device_commands as earlier')
                        ->whereColumn('earlier.device_id', 'command.device_id')
                        ->whereColumn('earlier.sequence', '<', 'command.sequence')
                        ->whereNotIn('earlier.status', ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED']);
                })
                ->select('outbox.*')
                ->orderBy('outbox.id')
                ->limit($data['limit'] ?? 20)
                ->lock('FOR UPDATE OF outbox SKIP LOCKED')
                ->get();

            $claimed = [];
            foreach ($rows as $row) {
                $command = DeviceCommand::query()->whereKey($row->aggregate_id)->lockForUpdate()->first();
                if (! $command || in_array($command->status, ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'], true)) {
                    DB::table('transactional_outbox')->where('id', $row->id)->update([
                        'published_at' => $now,
                        'claim_id' => null,
                        'claimed_until' => null,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                if ($command->expires_at->isPast()) {
                    $this->expireCommand($command, $now);
                    DB::table('transactional_outbox')->where('id', $row->id)->update([
                        'published_at' => $now,
                        'claim_id' => null,
                        'claimed_until' => null,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                $claimId = (string) Str::uuid();
                DB::table('transactional_outbox')->where('id', $row->id)->update([
                    'claim_id' => $claimId,
                    'claimed_until' => $now->copy()->addSeconds(30),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => $now,
                ]);
                if ($command->status === 'PENDING') {
                    $command->forceFill(['status' => 'QUEUED'])->save();
                    $this->logCommand($command, 'device.command.queued', ['attempt' => $row->attempts + 1], 'GATEWAY');
                }

                $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $claimed[] = [
                    'outbox_id' => (int) $row->id,
                    'claim_id' => $claimId,
                    'device_id' => $command->device_id,
                    'command_id' => $command->id,
                    'command_type' => $command->command_type,
                    'sequence' => $command->sequence,
                    'payload' => $command->payload,
                    'expires_at' => $command->expires_at->toISOString(),
                    'attempt' => (int) $row->attempts + 1,
                ];
            }

            return $claimed;
        }, attempts: 3);

        return $this->success(['items' => $items], 'Command outbox berhasil diklaim.');
    }

    public function sent(Request $request, int $outbox): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate(['claim_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid']]);
        $result = DB::transaction(function () use ($device, $data, $outbox): string {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if (! $lockedDevice) {
                return 'wrong_device';
            }
            $record = DB::table('transactional_outbox')->where('id', $outbox)->lockForUpdate()->first();
            if (! $record || $record->claim_id !== strtolower($data['claim_id']) || $record->published_at !== null) {
                $this->recordRejection($lockedDevice, 'device.command.dispatch_rejected', [
                    'reason' => 'claim_mismatch', 'outbox_id' => $outbox,
                ]);

                return 'claim_mismatch';
            }
            $command = DeviceCommand::query()->whereKey($record->aggregate_id)->lockForUpdate()->first();
            if (! $command || $command->device_id !== $lockedDevice->id) {
                $this->recordRejection($lockedDevice, 'device.command.dispatch_rejected', [
                    'reason' => 'wrong_device', 'outbox_id' => $outbox, 'command_id' => $record->aggregate_id,
                ]);

                return 'wrong_device';
            }
            $session = DeviceSession::query()->whereKey($data['session_id'])->where('device_id', $device->id)
                ->where('state', 'AUTHENTICATED')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()->first();
            if (! $session) {
                $this->recordRejection($lockedDevice, 'device.command.dispatch_rejected', [
                    'reason' => 'stale_session', 'command_id' => $command->id,
                ]);

                return 'stale_session';
            }
            if ($command->expires_at->isPast()) {
                $this->expireCommand($command, now());
                DB::table('transactional_outbox')->where('id', $outbox)->update([
                    'published_at' => now(), 'claim_id' => null, 'claimed_until' => null, 'updated_at' => now(),
                ]);

                return 'expired';
            }

            if (! in_array($command->status, ['QUEUED', 'SENT'], true)) {
                $this->recordRejection($lockedDevice, 'device.command.dispatch_rejected', [
                    'reason' => 'invalid_state', 'command_id' => $command->id, 'status' => $command->status,
                ]);

                return 'invalid_state';
            }
            if ($command->status !== 'SENT') {
                $command->forceFill(['status' => 'SENT', 'sent_at' => now()])->save();
                $this->logCommand($command, 'device.command.sent', ['device_session_id' => $session->id], 'GATEWAY');
            }
            $delay = min(60, 2 ** min((int) $record->attempts, 6)) + random_int(0, 3);
            DB::table('transactional_outbox')->where('id', $outbox)->update([
                'available_at' => now()->addSeconds($delay),
                'claim_id' => null,
                'claimed_until' => null,
                'updated_at' => now(),
            ]);

            return 'sent';
        }, attempts: 3);

        return match ($result) {
            'sent' => $this->success(['accepted' => true, 'status' => 'SENT'], 'Pengiriman command dicatat.'),
            'expired' => $this->conflict('Command sudah kedaluwarsa.'),
            'stale_session' => $this->conflict('Sesi perangkat sudah tidak aktif.'),
            'wrong_device' => $this->failure('Command bukan milik perangkat yang terautentikasi.', 403),
            'claim_mismatch' => $this->conflict('Lease command sudah berubah atau kedaluwarsa.'),
            default => $this->conflict('Status command tidak mengizinkan pengiriman.'),
        };
    }

    public function defer(Request $request, int $outbox): JsonResponse
    {
        $data = $request->validate([
            'claim_id' => ['required', 'uuid'],
            'retry_after_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'reason' => ['required', 'string', 'in:DEVICE_OFFLINE,SEND_FAILED'],
        ]);
        $updated = DB::table('transactional_outbox')->where('id', $outbox)
            ->where('claim_id', strtolower($data['claim_id']))->whereNull('published_at')->update([
                'available_at' => now()->addSeconds($data['retry_after_seconds']),
                'claim_id' => null,
                'claimed_until' => null,
                'last_error' => $data['reason'],
                'updated_at' => now(),
            ]);

        return $updated === 1
            ? $this->success(['deferred' => true], 'Command dijadwalkan untuk dicoba kembali.')
            : $this->conflict('Lease command sudah berubah atau kedaluwarsa.');
    }

    public function acknowledge(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate([
            'command_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'status' => ['required', 'in:DELIVERED,RECEIVED,EXECUTING,SUCCESS,FAILED'],
            'result_code' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Z0-9_]+\z/'],
            'result_message' => ['nullable', 'string', 'max:500'],
        ]);
        $result = DB::transaction(function () use ($device, $data): string {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if (! $lockedDevice) {
                return 'wrong_device';
            }
            $outbox = DB::table('transactional_outbox')->where('aggregate_type', 'device_command')
                ->where('aggregate_id', $data['command_id'])->lockForUpdate()->first();
            $command = DeviceCommand::query()->whereKey($data['command_id'])->lockForUpdate()->first();
            if (! $command || $command->device_id !== $lockedDevice->id) {
                $this->recordRejection($lockedDevice, 'device.command.acknowledgement_rejected', [
                    'reason' => 'wrong_device', 'command_id' => $data['command_id'], 'status' => $data['status'],
                ]);

                return 'wrong_device';
            }
            $session = DeviceSession::query()->whereKey($data['session_id'])->where('device_id', $device->id)
                ->where('state', 'AUTHENTICATED')->lockForUpdate()->first();
            if (! $session) {
                $this->recordRejection($lockedDevice, 'device.command.acknowledgement_rejected', [
                    'reason' => 'stale_session', 'command_id' => $data['command_id'], 'status' => $data['status'],
                ]);

                return 'stale_session';
            }
            $target = $data['status'];
            if ($command->status === $target) {
                return 'duplicate';
            }
            if ($command->expires_at->isPast() && ! in_array($command->status, ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'], true)) {
                $this->expireCommand($command, now());
                $this->completeOutbox($command->id);

                return 'expired';
            }
            $allowed = match ($target) {
                'DELIVERED' => ['SENT'],
                'RECEIVED' => ['SENT', 'DELIVERED'],
                'EXECUTING' => ['RECEIVED'],
                'SUCCESS' => ['RECEIVED', 'EXECUTING'],
                'FAILED' => ['SENT', 'DELIVERED', 'RECEIVED', 'EXECUTING'],
            };
            if (! in_array($command->status, $allowed, true)) {
                $this->recordRejection($lockedDevice, 'device.command.acknowledgement_rejected', [
                    'reason' => 'invalid_transition',
                    'command_id' => $command->id,
                    'current_status' => $command->status,
                    'requested_status' => $target,
                ]);

                return 'invalid_transition';
            }

            $now = now();
            $changes = ['status' => $target, 'result_code' => $data['result_code'] ?? null, 'result_message' => $data['result_message'] ?? null];
            if ($target === 'DELIVERED') {
                $changes['delivered_at'] = $now;
            }
            if ($target === 'RECEIVED') {
                $changes['received_at'] = $now;
            }
            if ($target === 'EXECUTING') {
                $changes['executing_at'] = $now;
            }
            if ($target === 'DELIVERED') {
                $this->completeOutbox($command->id);
            }
            if (in_array($target, ['SUCCESS', 'FAILED'], true)) {
                $changes['completed_at'] = $now;
            }
            $command->forceFill($changes)->save();
            if ($target === 'SUCCESS' && $command->command_type === 'LOCK') {
                $lockedDevice->forceFill(['lock_state' => 'LOCKED'])->save();
            } elseif ($target === 'SUCCESS' && $command->command_type === 'UNLOCK') {
                $lockedDevice->forceFill(['lock_state' => 'UNLOCKED'])->save();
            }
            if (in_array($target, ['DELIVERED', 'SUCCESS', 'FAILED'], true) && $outbox) {
                DB::table('transactional_outbox')->where('id', $outbox->id)->update([
                    'published_at' => $now,
                    'claim_id' => null,
                    'claimed_until' => null,
                    'updated_at' => $now,
                ]);
            }
            $this->logCommand($command, 'device.command.'.strtolower($target), array_filter([
                'device_session_id' => $session->id,
                'result_code' => $data['result_code'] ?? null,
            ], fn ($value) => $value !== null), 'DEVICE');
            activity('device-commands')->performedOn($device)->withProperties([
                'command_id' => $command->id,
                'device_session_id' => $session->id,
                'status' => $target,
                'result_code' => $data['result_code'] ?? null,
            ])->log('device.command.'.strtolower($target));

            return 'accepted';
        }, attempts: 3);

        return match ($result) {
            'accepted', 'duplicate' => $this->success(['accepted' => true, 'duplicate' => $result === 'duplicate', 'status' => $data['status']], 'Acknowledgment command diterima.'),
            'stale_session' => $this->conflict('Sesi perangkat sudah tidak aktif.'),
            'wrong_device' => $this->failure('Command bukan milik perangkat yang terautentikasi.', 403),
            'expired' => $this->conflict('Command sudah kedaluwarsa dan ditolak.'),
            default => $this->conflict('Transisi status command tidak valid.'),
        };
    }

    private function completeOutbox(string $commandId): void
    {
        DB::table('transactional_outbox')->where('aggregate_type', 'device_command')
            ->where('aggregate_id', $commandId)->whereNull('published_at')->update([
                'published_at' => now(), 'claim_id' => null, 'claimed_until' => null, 'updated_at' => now(),
            ]);
    }

    private function expireCommand(DeviceCommand $command, $now): void
    {
        if (in_array($command->status, ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            return;
        }
        $previousStatus = $command->status;
        $command->forceFill(['status' => 'EXPIRED', 'completed_at' => $now, 'result_code' => 'COMMAND_EXPIRED'])->save();
        $this->logCommand($command, 'device.command.expired', ['reason' => 'expires_at_elapsed'], 'SYSTEM');
        $device = Device::query()->whereKey($command->device_id)->first();
        if ($device) {
            $this->recordRejection($device, 'device.command.expired', [
                'command_id' => $command->id,
                'previous_status' => $previousStatus,
                'reason' => 'expires_at_elapsed',
            ]);
        }
    }

    private function logCommand(DeviceCommand $command, string $event, array $details, string $actorType = 'SYSTEM'): void
    {
        DeviceCommandLog::query()->create([
            'command_id' => $command->id,
            'device_id' => $command->device_id,
            'correlation_id' => (string) Str::uuid(),
            'event' => $event,
            'actor_type' => $actorType,
            'details' => $details,
        ]);
    }

    private function recordRejection(Device $device, string $event, array $properties): void
    {
        activity('device-command-security')->performedOn($device)
            ->withProperties($properties)
            ->log($event);
    }
}
