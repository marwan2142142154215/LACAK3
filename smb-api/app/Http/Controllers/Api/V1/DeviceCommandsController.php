<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceCommands\DeviceCommandBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceCommandsController extends ApiController
{
    private const COMMAND_PERMISSION = [
        'LOCK' => 'devices.lock',
        'UNLOCK' => 'devices.unlock',
        'REQUEST_LOCATION' => 'devices.location',
        'REQUEST_CAMERA' => 'devices.camera',
    ];

    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $this->assertSiteScope($request, $device->site_id);
        $request->validate([
            'status' => ['sometimes', Rule::in(['PENDING', 'QUEUED', 'SENT', 'DELIVERED', 'RECEIVED', 'EXECUTING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'])],
            'command_type' => ['sometimes', Rule::in(array_keys(self::COMMAND_PERMISSION))],
        ]);
        $this->perPage($request);

        $query = DeviceCommand::query()->where('device_id', $device->id)->with('creator:id,name');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('command_type')) {
            $query->where('command_type', $request->query('command_type'));
        }

        return $this->paginated($query->orderByDesc('created_at')->paginate($this->perPage($request)), 'Riwayat command berhasil diambil.');
    }

    public function show(Request $request, Device $device, DeviceCommand $command): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $this->assertSiteScope($request, $device->site_id);
        abort_unless($command->device_id === $device->id, 404, 'Data tidak ditemukan.');

        return $this->success($command->load(['creator:id,name', 'logs']), 'Detail command berhasil diambil.');
    }

    public function store(Request $request, Device $device, DeviceCommandBroker $broker): JsonResponse
    {
        $this->authorizePermission($request, 'devices.command');
        $this->assertSiteScope($request, $device->site_id);
        $data = $request->validate([
            'command_type' => ['required', 'string', Rule::in(array_keys(self::COMMAND_PERMISSION))],
            'payload' => ['sometimes', 'array'],
            'expires_in_seconds' => ['sometimes', 'integer', 'min:30', 'max:86400'],
        ]);
        $type = $data['command_type'];
        $payload = $this->validateCommandPayload($type, $data['payload'] ?? []);
        $this->authorizePermission($request, self::COMMAND_PERMISSION[$type]);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        $this->validateIdempotencyKey($idempotencyKey);

        $result = $broker->enqueue(
            $device,
            $request->user(),
            $type,
            $idempotencyKey,
            $payload,
            (int) ($data['expires_in_seconds'] ?? 300),
        );
        if ($result['conflict']) {
            return $this->conflict('Idempotency-Key ini sudah dipakai untuk command dengan isi berbeda.');
        }

        $command = $result['command'];

        return $this->success([
            'command_id' => $command->id,
            'device_id' => $command->device_id,
            'command_type' => $command->command_type,
            'idempotency_key' => $command->idempotency_key,
            'sequence' => $command->sequence,
            'created_by' => $command->created_by,
            'created_at' => $command->created_at->toISOString(),
            'expires_at' => $command->expires_at->toISOString(),
            'status' => $command->status,
        ], $result['created'] ? 'Command dicatat dan menunggu gateway/device.' : 'Command idempoten yang sama sudah tercatat.', $result['created'] ? 201 : 200);
    }

    private function validateIdempotencyKey(string $value): void
    {
        abort_unless(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $value) === 1, 422, 'Header Idempotency-Key wajib diisi dengan format yang valid.');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validateCommandPayload(string $type, array $payload): array
    {
        if (in_array($type, ['LOCK', 'UNLOCK'], true)) {
            if ($payload !== []) {
                throw ValidationException::withMessages(['payload' => 'Command lock/unlock tidak menerima parameter tambahan.']);
            }

            return [];
        }

        $rules = match ($type) {
            'REQUEST_LOCATION' => [
                'payload' => ['required', 'array:priority,max_age_seconds'],
                'payload.priority' => ['sometimes', Rule::in(['LOW', 'BALANCED', 'HIGH'])],
                'payload.max_age_seconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            ],
            'REQUEST_CAMERA' => [
                'payload' => ['required', 'array:camera_facing'],
                'payload.camera_facing' => ['required', Rule::in(['FRONT', 'BACK'])],
            ],
            default => ['payload' => ['required', 'array']],
        };

        $validated = Validator::make(['payload' => $payload], $rules)->validate();

        return $validated['payload'];
    }
}
