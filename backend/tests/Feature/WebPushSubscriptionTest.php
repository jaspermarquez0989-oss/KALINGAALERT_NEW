<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendWebPushNotifications;
use App\Models\FireIncident;
use App\Models\User;
use App\Services\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_can_register_and_only_remove_their_own_browser_subscription(): void
    {
        $resident = User::factory()->create(['role' => UserRole::CommunityResident->value]);
        $otherResident = User::factory()->create(['role' => UserRole::CommunityResident->value]);
        $subscription = $this->subscription('https://fcm.googleapis.com/subscription/one');

        $this->withToken($this->tokenFor($resident))
            ->postJson('/api/v1/my/push-subscriptions', $subscription)
            ->assertCreated();

        $endpointHash = hash('sha256', $subscription['endpoint']);
        $this->assertDatabaseHas('user_web_push_subscriptions', [
            'user_id' => $resident->id,
            'endpoint_hash' => $endpointHash,
            'endpoint' => $subscription['endpoint'],
            'public_key' => $subscription['keys']['p256dh'],
            'auth_token' => $subscription['keys']['auth'],
        ]);

        $this->withToken($this->tokenFor($resident))
            ->getJson('/api/v1/my/push-subscriptions/status?endpoint='.urlencode($subscription['endpoint']))
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($otherResident))
            ->getJson('/api/v1/my/push-subscriptions/status?endpoint='.urlencode($subscription['endpoint']))
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($otherResident))
            ->deleteJson('/api/v1/my/push-subscriptions', ['endpoint' => $subscription['endpoint']])
            ->assertNoContent();

        $this->assertDatabaseHas('user_web_push_subscriptions', ['endpoint_hash' => $endpointHash]);

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($resident))
            ->deleteJson('/api/v1/my/push-subscriptions', ['endpoint' => $subscription['endpoint']])
            ->assertNoContent();

        $this->assertDatabaseMissing('user_web_push_subscriptions', ['endpoint_hash' => $endpointHash]);
    }

    public function test_only_residents_can_register_valid_https_subscriptions(): void
    {
        $staff = User::factory()->create(['role' => UserRole::BfpOfficer->value]);
        $resident = User::factory()->create(['role' => UserRole::CommunityResident->value]);
        $subscription = $this->subscription('https://fcm.googleapis.com/subscription/two');

        $this->withToken($this->tokenFor($staff))
            ->postJson('/api/v1/my/push-subscriptions', $subscription)
            ->assertForbidden();

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($resident))
            ->postJson('/api/v1/my/push-subscriptions', [
                ...$subscription,
                'endpoint' => 'http://push.example.test/insecure',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($resident))
            ->postJson('/api/v1/my/push-subscriptions', [
                ...$subscription,
                'endpoint' => 'https://example.test/untrusted',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);
    }

    public function test_resident_can_fetch_the_public_vapid_key_only_when_web_push_is_configured(): void
    {
        $resident = User::factory()->create(['role' => UserRole::CommunityResident->value]);
        config([
            'services.webpush.vapid.subject' => 'mailto:alerts@example.test',
            'services.webpush.vapid.public_key' => 'public-test-key',
            'services.webpush.vapid.private_key' => 'private-test-key',
        ]);

        $this->withToken($this->tokenFor($resident))
            ->getJson('/api/v1/my/push-subscriptions/vapid-key')
            ->assertOk()
            ->assertJsonPath('data.public_key', 'public-test-key');

        config(['services.webpush.vapid.private_key' => null]);

        $this->clearJwtRequestState();
        $this->withToken($this->tokenFor($resident))
            ->getJson('/api/v1/my/push-subscriptions/vapid-key')
            ->assertStatus(503);
    }

    public function test_verified_incident_pushes_target_only_active_residents_and_remove_expired_subscriptions(): void
    {
        $resident = User::factory()->create(['role' => UserRole::CommunityResident->value]);
        $inactiveResident = User::factory()->create([
            'role' => UserRole::CommunityResident->value,
            'is_active' => false,
        ]);
        $staff = User::factory()->create(['role' => UserRole::BfpOfficer->value]);
        $incident = FireIncident::create([
            'occurred_at' => now()->subHour(),
            'barangay' => 'Poblacion',
            'incident_type' => 'structure',
            'cause' => 'electrical',
            'latitude' => 12.73,
            'longitude' => 121.36,
            'source_reference' => 'BFP-WEB-PUSH-001',
            'verification_status' => 'verified',
            'submitted_by' => $staff->id,
            'reviewed_by' => $staff->id,
            'reviewed_at' => now(),
        ]);
        $residentEndpoint = 'https://fcm.googleapis.com/subscription/active';
        $inactiveEndpoint = 'https://fcm.googleapis.com/subscription/inactive';
        $staffEndpoint = 'https://fcm.googleapis.com/subscription/staff';

        foreach ([
            [$resident, $residentEndpoint],
            [$inactiveResident, $inactiveEndpoint],
            [$staff, $staffEndpoint],
        ] as [$user, $endpoint]) {
            $this->insertSubscription($user, $endpoint);
        }

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(function (Collection $subscriptions, string $payload) use ($residentEndpoint): bool {
                $payloadData = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return $subscriptions->count() === 1
                    && $subscriptions->first()->endpoint === $residentEndpoint
                    && $payloadData['title'] === 'Verified fire incident'
                    && $payloadData['url'] === '/dashboard';
            })
            ->andReturn([hash('sha256', $residentEndpoint)]);

        (new SendWebPushNotifications(
            SendWebPushNotifications::AUDIENCE_VERIFIED_FIRE_INCIDENT,
            (int) $incident->id,
            'Verified fire incident',
            'A fire incident was verified in Poblacion.',
            ['url' => '/dashboard', 'incidentId' => $incident->id],
        ))->handle($sender);

        $this->assertDatabaseMissing('user_web_push_subscriptions', [
            'endpoint_hash' => hash('sha256', $residentEndpoint),
        ]);
        $this->assertDatabaseHas('user_web_push_subscriptions', [
            'endpoint_hash' => hash('sha256', $inactiveEndpoint),
        ]);
        $this->assertDatabaseHas('user_web_push_subscriptions', [
            'endpoint_hash' => hash('sha256', $staffEndpoint),
        ]);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function subscription(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'public-key-test',
                'auth' => 'auth-token-test',
            ],
        ];
    }

    private function insertSubscription(User $user, string $endpoint): void
    {
        DB::table('user_web_push_subscriptions')->insert([
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => 'public-key-test',
            'auth_token' => 'auth-token-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        $this->clearJwtRequestState();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $token = $response->json('access_token');

        $this->clearJwtRequestState();

        return $token;
    }

    private function clearJwtRequestState(): void
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->app->forgetInstance('tymon.jwt');
    }
}
