<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'Anda tidak memiliki izin untuk operasi ini.');
    }

    protected function assertSiteScope(Request $request, int $siteId): void
    {
        abort_if(
            ! $request->user()->hasRole('SUPER_ADMIN') && ! $request->user()->sites()->whereKey($siteId)->exists(),
            404,
            'Data tidak ditemukan.'
        );
    }

    protected function perPage(Request $request): int
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return min(max((int) $request->query('per_page', 25), 1), 100);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'items' => $paginator->items(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
        ]);
    }

    protected function success(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function conflict(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => (object) []], 409);
    }

    protected function failure(string $message, int $status, array|object $errors = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    protected function audit(Request $request, Model $model, string $event, array $properties = []): void
    {
        activity('administration')
            ->causedBy($request->user())
            ->performedOn($model)
            ->withProperties($properties)
            ->log($event);
    }
}
