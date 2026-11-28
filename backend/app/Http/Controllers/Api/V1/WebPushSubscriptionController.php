<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebPushSubscriptionController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
        ]);

        $isSubscribed = DB::table('user_web_push_subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->exists();

        return response()->json([
            'data' => [
                'enabled' => $isSubscribed,
            ],
        ]);
    }

    public function vapidKey(): JsonResponse
    {
        $vapid = config('services.webpush.vapid');

        if (
            ! is_array($vapid)
            || ! is_string($vapid['subject'] ?? null)
            || $vapid['subject'] === ''
            || ! is_string($vapid['public_key'] ?? null)
            || $vapid['public_key'] === ''
            || ! is_string($vapid['private_key'] ?? null)
            || $vapid['private_key'] === ''
        ) {
            return response()->json(['message' => 'Browser push notifications are not configured on the server.'], 503);
        }

        return response()->json([
            'data' => [
                'public_key' => $vapid['public_key'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => [
                'bail',
                'required',
                'string',
                'max:2048',
                'url:https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isTrustedPushEndpoint($value)) {
                        $fail('The endpoint must belong to a supported browser push service.');
                    }
                },
            ],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
        ]);
        $endpoint = $validated['endpoint'];
        $now = now();

        DB::table('user_web_push_subscriptions')->upsert([[
            'user_id' => $request->user()->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => $validated['keys']['p256dh'],
            'auth_token' => $validated['keys']['auth'],
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['endpoint_hash'], ['user_id', 'endpoint', 'public_key', 'auth_token', 'updated_at']);

        return response()->json(['message' => 'Browser push subscription registered.'], 201);
    }

    private function isTrustedPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? null;

        if (
            ! is_string($host)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        return in_array($host, [
            'fcm.googleapis.com',
            'updates.push.services.mozilla.com',
            'push.services.mozilla.com',
            'web.push.apple.com',
        ], true) || str_ends_with($host, '.notify.windows.com');
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
        ]);

        DB::table('user_web_push_subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->json([], 204);
    }
}
