<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Services\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use UnexpectedValueException;

class SendWebPushNotifications implements ShouldQueue
{
    use Queueable;

    public const AUDIENCE_ALERT = 'alert';

    public const AUDIENCE_VERIFIED_FIRE_INCIDENT = 'verified_fire_incident';

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $audience,
        public int $sourceId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(WebPushSender $sender): void
    {
        $invalidEndpointHashes = [];
        $payload = json_encode([
            ...$this->data,
            'title' => $this->title,
            'body' => $this->body,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->recipientSubscriptions()
            ->orderBy('user_web_push_subscriptions.id')
            ->chunk(100, function (Collection $subscriptions) use ($sender, $payload, &$invalidEndpointHashes): void {
                $invalidEndpointHashes = [
                    ...$invalidEndpointHashes,
                    ...$sender->send($subscriptions, $payload),
                ];
            });

        if ($invalidEndpointHashes !== []) {
            DB::table('user_web_push_subscriptions')
                ->whereIn('endpoint_hash', array_unique($invalidEndpointHashes))
                ->delete();
        }
    }

    private function recipientSubscriptions(): Builder
    {
        $query = DB::table('user_web_push_subscriptions')
            ->join('users', 'users.id', '=', 'user_web_push_subscriptions.user_id')
            ->where('users.role', 'resident')
            ->where('users.is_active', true)
            ->select(
                'user_web_push_subscriptions.id',
                'user_web_push_subscriptions.endpoint',
                'user_web_push_subscriptions.endpoint_hash',
                'user_web_push_subscriptions.public_key',
                'user_web_push_subscriptions.auth_token',
            );

        if ($this->audience === self::AUDIENCE_ALERT) {
            return $query
                ->join('alert_recipients', 'alert_recipients.user_id', '=', 'user_web_push_subscriptions.user_id')
                ->join('alerts', 'alerts.id', '=', 'alert_recipients.alert_id')
                ->where('alerts.id', $this->sourceId)
                ->where('alerts.status', Alert::STATUS_PUBLISHED);
        }

        if ($this->audience === self::AUDIENCE_VERIFIED_FIRE_INCIDENT) {
            return $query->whereExists(function (Builder $incidentQuery): void {
                $incidentQuery
                    ->selectRaw('1')
                    ->from('fire_incidents')
                    ->where('fire_incidents.id', $this->sourceId)
                    ->where('fire_incidents.verification_status', 'verified');
            });
        }

        throw new UnexpectedValueException('Unsupported web push notification audience.');
    }
}
