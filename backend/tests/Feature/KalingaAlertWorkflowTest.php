<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\HazardSource;
use App\Models\RiskArea;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KalingaAlertWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_registration_cannot_assign_a_staff_role(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'A Resident',
            'email' => 'resident@example.com',
            'barangay' => 'Poblacion',
            'sitio' => 'Purok 1',
            'role' => UserRole::SystemAdministrator->value,
            'password' => 'ResidentPass123',
            'password_confirmation' => 'ResidentPass123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'resident@example.com',
            'role' => UserRole::CommunityResident->value,
        ]);
    }

    public function test_web_registration_delivers_matching_active_advisories(): void
    {
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $operator = $this->makeUser(UserRole::AlertOperator->value);
        $area = $this->createRiskArea($manager);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Official weather advisory',
            'details' => 'Verified regional weather advisory.',
            'issued_at' => now()->subMinutes(10),
            'verification_status' => 'verified',
            'created_by' => $manager->id,
            'verified_by' => $manager->id,
            'verified_at' => now(),
        ]);
        $alert = Alert::create([
            'hazard_source_id' => $source->id,
            'title' => 'Current weather advisory',
            'message' => 'A current alert for residents in Purok 1.',
            'safety_instructions' => 'Follow local instructions.',
            'severity' => 'warning',
            'status' => Alert::STATUS_PUBLISHED,
            'starts_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
            'created_by' => $operator->id,
        ]);
        $alert->riskAreas()->attach($area->id, [
            'coverage_snapshot' => json_encode([
                'name' => $area->name,
                'barangay' => $area->barangay,
                'sitio' => $area->sitio,
                'classification' => $area->classification,
                'boundary' => $area->boundary,
            ]),
        ]);

        $this->post(route('register.store'), [
            'name' => 'Web Resident',
            'email' => 'web-resident@example.com',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
            'password' => 'ResidentPass123',
            'password_confirmation' => 'ResidentPass123',
        ])->assertRedirect(route('dashboard'));

        $resident = User::query()->where('email', 'web-resident@example.com')->firstOrFail();
        $this->assertDatabaseHas('alert_recipients', [
            'alert_id' => $alert->id,
            'user_id' => $resident->id,
        ]);
    }

    public function test_residents_cannot_prepare_alerts_or_access_incident_reporting(): void
    {
        $resident = $this->makeUser(UserRole::CommunityResident->value);

        $this->actingAs($resident)
            ->get(route('alerts.create'))
            ->assertForbidden();

        $this->actingAs($resident)
            ->get('/incident-reports')
            ->assertNotFound();
    }

    public function test_users_can_change_their_password_after_confirming_the_current_password(): void
    {
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'password' => 'OldResidentPass123',
            'barangay' => 'Poblacion',
        ]);

        $this->actingAs($resident)->put(route('profile.update'), [
            'name' => $resident->name,
            'email' => $resident->email,
            'barangay' => $resident->barangay,
            'current_password' => 'OldResidentPass123',
            'password' => 'NewResidentPass456',
            'password_confirmation' => 'NewResidentPass456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('NewResidentPass456', $resident->fresh()->password));
    }

    public function test_published_alerts_are_available_to_all_active_residents(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'UTC'));

        $operator = $this->makeUser(UserRole::AlertOperator->value);
        $approver = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $bfpOfficer = $this->makeUser(UserRole::BfpOfficer->value);
        $area = $this->createRiskArea($approver);
        $source = HazardSource::create([
            'source_type' => 'fire',
            'organization' => 'Bureau of Fire Protection (BFP)',
            'reference' => 'BFP-2026-001',
            'title' => 'Confirmed local fire information',
            'details' => 'Information confirmed by the local BFP office.',
            'issued_at' => now()->subMinutes(20),
            'verification_status' => 'verified',
            'created_by' => $bfpOfficer->id,
            'verified_by' => $approver->id,
            'verified_at' => now()->subMinutes(10),
        ]);
        $barangayResident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'bongabong',
            'sitio' => 'Purok 1',
        ]);
        $coordinateResident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'Other Barangay',
            'sitio' => 'Purok 4',
            'location_latitude' => 12.73,
            'location_longitude' => 121.36,
        ]);
        $outsideResident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 2',
            'location_latitude' => 12.80,
            'location_longitude' => 121.45,
        ]);

        $this->actingAs($operator)->post(route('alerts.store'), [
            'hazard_source_id' => $source->id,
            'title' => 'Verified fire advisory',
            'message' => 'An official fire advisory for the selected coverage.',
            'safety_instructions' => 'Follow instructions issued by local authorities.',
            'severity' => 'warning',
            'starts_at' => now()->format('Y-m-d H:i:s'),
            'ends_at' => '',
            'risk_area_ids' => [$area->id],
        ])->assertRedirect();

        $alert = Alert::query()->firstOrFail();
        $alert->update(['starts_at' => now()->addDay()]);
        $this->actingAs($approver)->patch(route('alerts.approve', $alert))->assertRedirect();
        $this->actingAs($approver)
            ->get(route('alerts.show', $alert))
            ->assertRedirect(config('services.frontend.url').'/alerts/'.$alert->id);
        $this->actingAs($operator)->patch(route('alerts.publish', $alert))->assertRedirect();

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'status' => Alert::STATUS_PUBLISHED,
            'starts_at' => '2026-10-27 12:00:00',
            'published_at' => '2026-10-27 12:00:00',
        ]);
        $this->assertDatabaseCount('alert_recipients', 3);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $barangayResident->id]);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $coordinateResident->id]);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $outsideResident->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'alert.published',
            'subject_id' => $alert->id,
        ]);

        $this->actingAs($barangayResident)
            ->get(route('resident.alerts.status'))
            ->assertOk()
            ->assertJson([
                'active_alert_count' => 1,
                'unread_count' => 1,
                'alerts' => [[
                    'id' => $alert->id,
                    'title' => 'Verified fire advisory',
                    'url' => route('resident.alerts.show', $alert),
                ]],
            ]);

        $this->actingAs($outsideResident)
            ->get(route('resident.alerts.status'))
            ->assertOk()
            ->assertJson([
                'active_alert_count' => 1,
                'unread_count' => 1,
                'alerts' => [[
                    'id' => $alert->id,
                    'title' => 'Verified fire advisory',
                    'url' => route('resident.alerts.show', $alert),
                ]],
            ]);

        $this->actingAs($outsideResident)
            ->get(route('resident.alerts.show', $alert))
            ->assertRedirect(config('services.frontend.url').'/my-alerts/'.$alert->id);

        $this->actingAs($barangayResident)
            ->get(route('resident.alerts.show', $alert))
            ->assertRedirect(config('services.frontend.url').'/my-alerts/'.$alert->id);

        $this->assertDatabaseHas('alert_recipients', [
            'alert_id' => $alert->id,
            'user_id' => $barangayResident->id,
        ]);
        $this->assertNotNull($alert->recipients()->where('user_id', $barangayResident->id)->firstOrFail()->read_at);

        $this->actingAs($barangayResident)
            ->get(route('dashboard'))
            ->assertRedirect(config('services.frontend.url').'/dashboard');

        $this->actingAs($outsideResident)
            ->get(route('dashboard'))
            ->assertRedirect(config('services.frontend.url').'/dashboard');

        $this->actingAs($barangayResident)
            ->get(route('resident.alerts.status'))
            ->assertOk()
            ->assertJson([
                'active_alert_count' => 1,
                'unread_count' => 0,
                'alerts' => [],
            ]);
    }

    public function test_resident_alert_status_endpoint_requires_a_resident_account(): void
    {
        $staff = $this->makeUser(UserRole::MdrrmoPersonnel->value);

        $this->get(route('resident.alerts.status'))
            ->assertRedirect(route('login'));

        $this->actingAs($staff)
            ->get(route('resident.alerts.status'))
            ->assertForbidden();
    }

    public function test_scheduled_resident_alert_appears_when_its_start_time_is_reached(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:03:00', 'UTC'));

        $creator = $this->makeUser(UserRole::AlertOperator->value);
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
        ]);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Scheduled PAGASA advisory',
            'details' => 'Official advisory for a scheduled alert test.',
            'issued_at' => now(),
            'verification_status' => 'verified',
            'created_by' => $creator->id,
        ]);
        $alert = Alert::create([
            'hazard_source_id' => $source->id,
            'title' => 'Scheduled community alert',
            'message' => 'A scheduled advisory.',
            'safety_instructions' => 'Follow official safety instructions.',
            'severity' => 'warning',
            'status' => Alert::STATUS_PUBLISHED,
            'starts_at' => CarbonImmutable::parse('2026-10-27 20:04:00', 'UTC'),
            'published_at' => now(),
            'created_by' => $creator->id,
        ]);
        $alert->recipients()->create([
            'user_id' => $resident->id,
            'delivered_at' => now(),
        ]);

        $this->actingAs($resident)
            ->get(route('resident.alerts.status'))
            ->assertOk()
            ->assertJson([
                'active_alert_count' => 0,
                'unread_count' => 0,
                'alerts' => [],
            ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-27 20:04:00', 'UTC'));

        $this->actingAs($resident)
            ->get(route('resident.alerts.status'))
            ->assertOk()
            ->assertJson([
                'active_alert_count' => 1,
                'unread_count' => 1,
                'alerts' => [[
                    'id' => $alert->id,
                    'title' => 'Scheduled community alert',
                    'url' => route('resident.alerts.show', $alert),
                ]],
            ]);

        $this->travelBack();
    }

    public function test_alert_approval_and_publication_require_different_staff_accounts(): void
    {
        $creator = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $area = $this->createRiskArea($creator);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'reference' => 'PAGASA-2026-002',
            'title' => 'Weather advisory',
            'details' => 'Official PAGASA weather advisory.',
            'issued_at' => now()->subMinutes(10),
            'verification_status' => 'verified',
            'created_by' => $creator->id,
            'verified_by' => $this->makeUser(UserRole::SystemAdministrator->value)->id,
            'verified_at' => now(),
        ]);

        $this->actingAs($creator)
            ->get(route('alerts.create'))
            ->assertRedirect(config('services.frontend.url').'/alerts/create');

        $this->actingAs($creator)->post(route('alerts.store'), [
            'hazard_source_id' => $source->id,
            'title' => 'Weather warning',
            'message' => 'Weather warning details.',
            'safety_instructions' => 'Follow official safety instructions.',
            'severity' => 'watch',
            'starts_at' => now()->format('Y-m-d H:i:s'),
            'risk_area_ids' => [$area->id],
        ])->assertRedirect();

        $alert = Alert::query()->firstOrFail();
        $this->actingAs($creator)->patch(route('alerts.approve', $alert))->assertSessionHasErrors('approval');
        $this->assertSame(Alert::STATUS_DRAFT, $alert->fresh()->status);
    }

    public function test_alert_schedule_is_saved_as_utc_from_philippine_local_time(): void
    {
        $creator = $this->makeUser(UserRole::AlertOperator->value);
        $approver = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Weather advisory',
            'details' => 'Verified regional weather advisory.',
            'issued_at' => now()->subMinutes(10),
            'verification_status' => 'verified',
            'verified_by' => $approver->id,
            'verified_at' => now(),
        ]);
        $area = $this->createRiskArea($approver);

        $this->actingAs($creator)->post(route('alerts.store'), [
            'hazard_source_id' => $source->id,
            'title' => 'Scheduled weather warning',
            'message' => 'Weather warning details.',
            'safety_instructions' => 'Follow official safety instructions.',
            'severity' => 'warning',
            'starts_at' => '2026-10-27T20:04',
            'ends_at' => '2026-10-27T21:04',
            'risk_area_ids' => [$area->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('alerts', [
            'title' => 'Scheduled weather warning',
            'starts_at' => '2026-10-27 12:04:00',
            'ends_at' => '2026-10-27 13:04:00',
        ]);

        $alert = Alert::query()->where('title', 'Scheduled weather warning')->firstOrFail();

        $this->actingAs($creator)
            ->get(route('alerts.edit', $alert))
            ->assertRedirect(config('services.frontend.url').'/alerts/'.$alert->id.'/edit');
    }

    public function test_staff_dashboard_renders_without_exposing_resident_locations(): void
    {
        $staff = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'Poblacion',
            'location_latitude' => 12.73,
            'location_longitude' => 121.36,
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertRedirect(config('services.frontend.url').'/dashboard')
            ->assertDontSee('121.36');

        $this->assertDatabaseHas('users', ['id' => $resident->id]);
    }

    public function test_risk_area_boundaries_must_be_closed_and_mappable(): void
    {
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $response = $this->actingAs($manager)->post(route('risk-areas.store'), [
            'name' => 'Unclosed polygon',
            'barangay' => 'Poblacion',
            'classification' => 'Flood-prone',
            'is_active' => '1',
            'latitude' => 12.75,
            'longitude' => 121.35,
            'boundary' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[121.3, 12.7], [121.4, 12.7], [121.4, 12.8], [121.3, 12.8]]],
            ]),
        ]);

        $response->assertSessionHasErrors('boundary');
        $this->assertDatabaseCount('risk_areas', 0);
    }

    public function test_fire_officer_can_submit_fire_information_but_cannot_review_it(): void
    {
        $officer = $this->makeUser(UserRole::BfpOfficer->value);
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);

        $this->actingAs($officer)->post(route('hazard-sources.store'), [
            'source_type' => 'fire',
            'reference' => 'BFP-2026-009',
            'title' => 'Confirmed fire information',
            'details' => 'Incident details confirmed by the Bureau of Fire Protection.',
            'issued_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $source = HazardSource::query()->firstOrFail();
        $this->assertSame('pending', $source->verification_status);
        $this->assertSame('Bureau of Fire Protection (BFP)', $source->organization);

        $this->actingAs($officer)
            ->patch(route('hazard-sources.review', $source), ['decision' => 'verified'])
            ->assertRedirect()
            ->assertSessionHasErrors('decision');

        $this->actingAs($manager)
            ->patch(route('hazard-sources.review', $source), ['decision' => 'verified'])
            ->assertRedirect();

        $this->assertSame('verified', $source->fresh()->verification_status);
    }

    #[DataProvider('staffRoles')]
    public function test_all_staff_roles_can_review_pending_sources(string $role): void
    {
        $submitter = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $reviewer = $this->makeUser($role);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Regional weather warning',
            'details' => 'A pending source record for staff review.',
            'issued_at' => now()->subMinutes(5),
            'verification_status' => 'pending',
            'created_by' => $submitter->id,
        ]);

        $this->actingAs($reviewer)
            ->get(route('hazard-sources.index'))
            ->assertRedirect(config('services.frontend.url').'/hazard-sources');

        $this->actingAs($reviewer)
            ->get(route('hazard-sources.review.show', $source))
            ->assertRedirect(config('services.frontend.url').'/hazard-sources/'.$source->id.'/review');

        $this->actingAs($reviewer)
            ->patch(route('hazard-sources.review', $source), ['decision' => 'verified'])
            ->assertRedirect();

        $this->assertDatabaseHas('hazard_sources', [
            'id' => $source->id,
            'verification_status' => 'verified',
            'verified_by' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $reviewer->id,
            'action' => 'hazard_source.verified',
            'subject_id' => $source->id,
        ]);
    }

    public static function staffRoles(): array
    {
        return [
            'system administrator' => [UserRole::SystemAdministrator->value],
            'MDRRMO personnel' => [UserRole::MdrrmoPersonnel->value],
            'BFP officer' => [UserRole::BfpOfficer->value],
            'alert operator' => [UserRole::AlertOperator->value],
        ];
    }

    public function test_resident_cannot_review_a_pending_source(): void
    {
        $submitter = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $resident = $this->makeUser(UserRole::CommunityResident->value);
        $source = HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Regional weather warning',
            'details' => 'A pending source record for staff review.',
            'issued_at' => now()->subMinutes(5),
            'verification_status' => 'pending',
            'created_by' => $submitter->id,
        ]);

        $this->actingAs($resident)
            ->get(route('hazard-sources.review.show', $source))
            ->assertForbidden();

        $this->actingAs($resident)
            ->patch(route('hazard-sources.review', $source), ['decision' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('hazard_sources', [
            'id' => $source->id,
            'verification_status' => 'pending',
            'verified_by' => null,
        ]);
    }

    public function test_staff_cannot_review_their_own_source_submission(): void
    {
        $submitter = $this->makeUser(UserRole::BfpOfficer->value);
        $source = HazardSource::create([
            'source_type' => 'fire',
            'organization' => 'Bureau of Fire Protection (BFP)',
            'title' => 'Fire information submitted by staff',
            'details' => 'A pending source record for independent review.',
            'issued_at' => now()->subMinutes(5),
            'verification_status' => 'pending',
            'created_by' => $submitter->id,
        ]);

        $this->actingAs($submitter)
            ->get(route('hazard-sources.review.show', $source))
            ->assertRedirect(config('services.frontend.url').'/hazard-sources/'.$source->id.'/review');

        $this->actingAs($submitter)
            ->from(route('hazard-sources.index'))
            ->patch(route('hazard-sources.review', $source), ['decision' => 'verified'])
            ->assertRedirect(route('hazard-sources.index'))
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('hazard_sources', [
            'id' => $source->id,
            'verification_status' => 'pending',
            'verified_by' => null,
        ]);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function createRiskArea(User $creator): RiskArea
    {
        return RiskArea::create([
            'name' => 'Riverside coverage',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
            'classification' => 'Flood-prone',
            'boundary' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [121.30, 12.70],
                    [121.40, 12.70],
                    [121.40, 12.76],
                    [121.30, 12.76],
                    [121.30, 12.70],
                ]],
            ],
            'is_active' => true,
            'created_by' => $creator->id,
        ]);
    }
}
