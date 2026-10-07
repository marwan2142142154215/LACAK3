<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateGatewayService
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('smb.gateway_shared_secret', '');
        if (strlen($secret) < 32) {
            return response()->json([
                'success' => false,
                'message' => 'Gateway service authentication is not configured.',
                'errors' => (object) [],
            ], 503);
        }

        $timestamp = $request->header('X-SMB-Timestamp', '');
        $requestId = $request->header('X-SMB-Request-ID', '');
        $providedBodyHash = $request->header('X-SMB-Body-SHA256', '');
        $providedSignature = $request->header('X-SMB-Signature', '');
        $serviceName = $request->header('X-SMB-Service', '');
        if ($serviceName !== 'adonisjs'
            || ! preg_match('/\A[0-9]{10}\z/', $timestamp)
            || ! preg_match('/\A[0-9a-fA-F-]{36}\z/', $requestId)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $providedBodyHash)
            || ! preg_match('/\Av1=[0-9a-f]{64}\z/', $providedSignature)
            || abs(now()->timestamp - (int) $timestamp) > (int) config('smb.gateway_clock_skew_seconds', 60)) {
            return $this->reject($request, 'invalid_headers');
        }

        $bodyHash = hash('sha256', $request->getContent());
        if (! hash_equals($bodyHash, $providedBodyHash)) {
            return $this->reject($request, 'body_hash_mismatch');
        }
        $authorizationHash = hash('sha256', (string) $request->header('Authorization', ''));
        $canonical = implode("\n", [
            $timestamp,
            strtolower($requestId),
            $request->method(),
            $request->getRequestUri(),
            $bodyHash,
            $authorizationHash,
        ]);
        $expectedSignature = 'v1='.hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return $this->reject($request, 'signature_mismatch');
        }

        try {
            $nonceAccepted = Cache::add('gateway:request:'.strtolower($requestId), true, now()->addMinutes(2));
        } catch (Throwable $exception) {
            Log::error('Gateway request replay protection unavailable.', ['exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gateway request could not be verified.',
                'errors' => (object) [],
            ], 503);
        }

        if (! $nonceAccepted) {
            return $this->reject($request, 'replayed_request');
        }

        $request->attributes->set('smb.gateway_service', 'adonisjs');

        return $next($request);
    }

    private function reject(Request $request, string $reason): Response
    {
        Log::warning('Rejected gateway service request.', [
            'request_id' => $request->header('X-SMB-Request-ID'),
            'reason' => $reason,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gateway service authentication failed.',
            'errors' => (object) [],
        ], 401);
    }
}
