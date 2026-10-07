<?php

namespace App\Services\DeviceCommands;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCommandLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCommandBroker
{
    /** @param array<string, mixed> $payload
     * @return array{command: DeviceCommand, created: bool, conflict: bool}
     */
    public function enqueue(Device $device, User $actor, string $type, string $idempotencyKey, array $payload, int $expiresInSeconds): array
    {
        return DB::transaction(function () use ($device, $actor, $type, $idempotencyKey, $payload, $expiresInSeconds): array {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $existing = DeviceCommand::query()
                ->where('device_id', $lockedDevice->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $sameRequest = $existing->command_type === $type && $existing->payload === $payload;

                return ['command' => $existing, 'created' => false, 'conflict' => ! $sameRequest];
            }

            $sequence = ((int) DeviceCommand::query()
                ->where('device_id', $lockedDevice->id)
                ->max('sequence')) + 1;
            $now = now();
            $command = DeviceCommand::query()->create([
                'device_id' => $lockedDevice->id,
                'sequence' => $sequence,
                'command_type' => $type,
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'status' => 'PENDING',
                'created_by' => $actor->id,
                'expires_at' => $now->copy()->addSeconds($expiresInSeconds),
            ]);

            DB::table('transactional_outbox')->insert([
                'aggregate_type' => 'device_command',
                'aggregate_id' => $command->id,
                'event' => 'device.command.created',
                'payload' => json_encode([
                    'command_id' => $command->id,
                    'device_id' => $command->device_id,
                    'command_type' => $command->command_type,
                    'sequence' => $command->sequence,
                    'payload' => $command->payload,
                    'expires_at' => $command->expires_at->toISOString(),
                ], JSON_THROW_ON_ERROR),
                'available_at' => $now,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DeviceCommandLog::query()->create([
                'command_id' => $command->id,
                'device_id' => $lockedDevice->id,
                'actor_user_id' => $actor->id,
                'correlation_id' => (string) Str::uuid(),
                'event' => 'device.command.created',
                'actor_type' => 'USER',
                'details' => ['status' => 'PENDING', 'command_type' => $type, 'sequence' => $sequence],
            ]);
            activity('device-commands')->causedBy($actor)->performedOn($lockedDevice)->withProperties([
                'command_id' => $command->id,
                'command_type' => $type,
                'sequence' => $sequence,
                'expires_at' => $command->expires_at->toISOString(),
            ])->log('device.command.created');

            return ['command' => $command, 'created' => true, 'conflict' => false];
        }, attempts: 3);
    }
}
