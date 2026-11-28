<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushSender
{
    /**
     * @param  Collection<int, object>  $subscriptions
     * @return list<string>
     */
    public function send(Collection $subscriptions, string $payload): array
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
            throw new RuntimeException('Web Push VAPID credentials are not configured.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapid['subject'],
                'publicKey' => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
            ],
        ], [
            'TTL' => 3600,
            'urgency' => 'high',
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->public_key,
                        'auth' => $subscription->auth_token,
                    ],
                ]),
                $payload,
            );
        }

        $expiredEndpointHashes = [];

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $endpointHash = hash('sha256', $report->getEndpoint());
            Log::warning('Web Push notification delivery failed.', [
                'endpoint_hash' => $endpointHash,
                'reason' => $report->getReason(),
            ]);

            if ($report->isSubscriptionExpired()) {
                $expiredEndpointHashes[] = $endpointHash;
            }
        }

        return array_values(array_unique($expiredEndpointHashes));
    }
}
