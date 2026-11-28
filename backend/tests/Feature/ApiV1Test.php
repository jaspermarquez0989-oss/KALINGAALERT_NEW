<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\FireRiskPrediction;
use App\Models\HazardSource;
use App\Models\RiskArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_registration_issues_a_token_without_accepting_privileged_roles_or_exposing_private_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'API Resident',
            'email' => 'api-resident@example.com',
            'barangay' => 'Poblacion',
            'sitio' => 'Purok 1',
            'location_latitude' => 12.73,
            'location_longitude' => 121.36,
            'role' => UserRole::SystemAdministrator->value,
            'password' => 'ResidentPass123',
            'password_confirmation' => 'ResidentPass123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('data.role', UserRole::CommunityResident->value)
            ->assertJsonMissingPath('data.location_latitude')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'email' => 'api-resident@example.com',
            'role' => UserRole::CommunityResident->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $response->json('data.id'),
            'action' => 'auth.registered',
        ]);

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'api-resident@example.com');
    }

    public function test_new_residents_see_all_active_risk_areas_and_receive_active_advisories(): void
    {
        $operator = $this->makeUser(UserRole::AlertOperator->value);
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $sourceAuthor = $this->makeUser(UserRole::BfpOfficer->value);
        $source = $this->makeVerifiedSource($sourceAuthor, $manager);
        $area = $this->makeRiskArea($manager);
        $otherArea = $this->makeRiskArea($manager);
        $otherArea->update(['sitio' => 'Purok 2']);
        $inactiveArea = $this->makeRiskArea($manager);
        $inactiveArea->update(['is_active' => false]);
        $alert = Alert::create([
            'hazard_source_id' => $source->id,
            'title' => 'Active community advisory',
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

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Bongabong Resident',
            'email' => 'new-resident@example.com',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
            'password' => 'ResidentPass123',
            'password_confirmation' => 'ResidentPass123',
        ])->assertCreated();
        $resident = User::query()->where('email', 'new-resident@example.com')->firstOrFail();

        $this->assertDatabaseHas('alert_recipients', [
            'alert_id' => $alert->id,
            'user_id' => $resident->id,
        ]);

        $outsideResident = $this->makeUser(UserRole::CommunityResident->value, [
            'barangay' => 'Another Barangay',
            'sitio' => 'Purok 9',
        ]);

        $this->withToken($registration->json('access_token'))
            ->getJson('/api/v1/my/alerts/status')
            ->assertOk()
            ->assertJsonPath('active_alert_count', 1)
            ->assertJsonPath('unread_count', 1);

        $this->withToken($registration->json('access_token'))
            ->getJson('/api/v1/my/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.alert.title', 'Active community advisory');

        $coverageResponse = $this->withToken($registration->json('access_token'))
            ->getJson('/api/v1/my/coverage')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.location_latitude');
        $visibleAreaIds = collect($coverageResponse->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$area->id, $otherArea->id], $visibleAreaIds);
        $this->assertNotContains($inactiveArea->id, $visibleAreaIds);

        $this->withToken($this->tokenFor($outsideResident))
            ->getJson('/api/v1/my/alerts/status')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('alerts.0.title', 'Active community advisory');
    }

    public function test_api_routes_require_a_valid_active_account_token(): void
    {
        $inactiveUser = $this->makeUser(UserRole::AlertOperator->value, [
            'email' => 'inactive@example.com',
        ]);
        $token = $this->tokenFor($inactiveUser);
        $inactiveUser->update(['is_active' => false]);

        $this->clearJwtRequestState();
        $this->getJson('/api/v1/alerts')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $inactiveUser->email,
            'password' => 'TestPassword123',
        ])->assertUnauthorized();

        $this->clearJwtRequestState();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_tokens_can_be_refreshed_and_revoked(): void
    {
        $user = $this->makeUser(UserRole::CommunityResident->value);
        $token = $this->tokenFor($user);

        $refreshedToken = $this->withToken($token)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->json('access_token');

        $this->clearJwtRequestState();
        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->clearJwtRequestState();
        $this->withToken($refreshedToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->clearJwtRequestState();
        $this->withToken($refreshedToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_staff_can_create_approve_publish_and_filter_alerts_through_the_api(): void
    {
        $creator = $this->makeUser(UserRole::AlertOperator->value, ['email' => 'operator@example.com']);
        $approver = $this->makeUser(UserRole::MdrrmoPersonnel->value, ['email' => 'manager@example.com']);
        $sourceAuthor = $this->makeUser(UserRole::BfpOfficer->value, ['email' => 'bfp@example.com']);
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'email' => 'recipient@example.com',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
        ]);
        $creatorToken = $this->tokenFor($creator);
        $approverToken = $this->tokenFor($approver);
        $this->clearJwtRequestState();
        $source = $this->makeVerifiedSource($sourceAuthor, $approver);
        $area = $this->makeRiskArea($approver);

        $createResponse = $this->withToken($creatorToken)->postJson('/api/v1/alerts', [
            'hazard_source_id' => $source->id,
            'title' => 'Community weather warning',
            'message' => 'Heavy rainfall may affect the community.',
            'safety_instructions' => 'Follow instructions from local authorities.',
            'severity' => 'warning',
            'intensity' => 4,
            'starts_at' => now()->toIso8601String(),
            'risk_area_ids' => [$area->id],
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.status', Alert::STATUS_DRAFT)
            ->assertJsonPath('data.intensity', 4);

        $alert = Alert::query()->where('title', 'Community weather warning')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $creator->id,
            'action' => 'alert.drafted',
            'subject_id' => $alert->id,
        ]);
        $this->assertDatabaseHas('alerts', ['id' => $alert->id, 'intensity' => 4]);

        $this->withToken($creatorToken)
            ->patchJson("/api/v1/alerts/{$alert->id}", [
                'title' => 'Updated community weather warning',
                'intensity' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated community weather warning')
            ->assertJsonPath('data.intensity', 5)
            ->assertJsonPath('data.message', 'Heavy rainfall may affect the community.');

        $alert->refresh();

        $this->clearJwtRequestState();
        $this->withToken($approverToken)
            ->patchJson("/api/v1/alerts/{$alert->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_APPROVED);

        $this->clearJwtRequestState();
        $this->withToken($approverToken)
            ->patchJson("/api/v1/alerts/{$alert->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_PUBLISHED);

        $this->assertDatabaseHas('alert_recipients', [
            'alert_id' => $alert->id,
            'user_id' => $resident->id,
        ]);
        $area->update(['name' => 'Updated area name']);

        $this->withToken($creatorToken)
            ->getJson('/api/v1/alerts?status=published&severity=warning&sort=title&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alert->id)
            ->assertJsonPath('data.0.risk_areas.0.id', $area->id);

        $this->withToken($creatorToken)
            ->getJson('/api/v1/alerts?sort=created_at%20DESC')
            ->assertUnprocessable();

        $this->withToken($this->tokenFor($creator))
            ->postJson('/api/v1/alerts', [
                'hazard_source_id' => $source->id,
                'title' => 'Invalid intensity',
                'message' => 'This intensity should be rejected.',
                'safety_instructions' => 'Follow instructions from local authorities.',
                'severity' => 'warning',
                'intensity' => 6,
                'starts_at' => now()->toIso8601String(),
                'risk_area_ids' => [$area->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['intensity']);

        $alert->update(['ends_at' => now()->subMinute()]);

        $revisionResponse = $this->withToken($creatorToken)
            ->postJson("/api/v1/alerts/{$alert->id}/revise")
            ->assertCreated()
            ->assertJsonPath('data.status', Alert::STATUS_DRAFT)
            ->assertJsonPath('data.intensity', 5)
            ->assertJsonPath('data.ends_at', null);

        $revision = Alert::query()->findOrFail($revisionResponse->json('data.id'));

        $this->assertDatabaseHas('alerts', [
            'id' => $revision->id,
            'supersedes_id' => $alert->id,
            'status' => Alert::STATUS_DRAFT,
            'intensity' => 5,
            'ends_at' => null,
        ]);

        $this->clearJwtRequestState();
        $this->withToken($approverToken)
            ->patchJson("/api/v1/alerts/{$revision->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_APPROVED);

        $this->clearJwtRequestState();
        $this->withToken($creatorToken)
            ->patchJson("/api/v1/alerts/{$revision->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_PUBLISHED);

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'status' => Alert::STATUS_EXPIRED,
        ]);
    }

    public function test_fire_advisories_require_a_verified_fire_source_matching_the_selected_type(): void
    {
        $creator = $this->makeUser(UserRole::AlertOperator->value);
        $sourceAuthor = $this->makeUser(UserRole::BfpOfficer->value);
        $verifier = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $source = HazardSource::create([
            'source_type' => 'fire',
            'organization' => 'Bureau of Fire Protection (BFP)',
            'title' => 'Verified BFP fire information',
            'details' => 'Official fire information for advisory testing.',
            'issued_at' => now()->subMinute(),
            'verification_status' => 'verified',
            'created_by' => $sourceAuthor->id,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);
        $prediction = FireRiskPrediction::create([
            'barangay' => 'Bongabong',
            'latitude' => 12.73,
            'longitude' => 121.36,
            'score_percent' => 68.5,
            'threshold' => 0.5,
            'threshold_met' => true,
            'model_version' => 'test',
            'prediction_radius_meters' => 500,
            'created_by' => $creator->id,
        ]);
        $token = $this->tokenFor($creator);
        $payload = [
            'hazard_source_id' => $source->id,
            'hazard_type' => 'fire',
            'fire_risk_prediction_id' => $prediction->id,
            'title' => 'Barangay fire advisory',
            'message' => 'Official fire information for the selected barangay.',
            'safety_instructions' => 'Follow BFP and local authority instructions.',
            'severity' => 'warning',
            'starts_at' => now()->toIso8601String(),
        ];

        $this->withToken($token)
            ->postJson('/api/v1/alerts', [...$payload, 'hazard_type' => 'weather'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hazard_source_id']);

        $this->clearJwtRequestState();
        $this->withToken($token)
            ->postJson('/api/v1/alerts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.hazard_source.source_type', 'fire')
            ->assertJsonPath('data.affected_barangay', 'Bongabong')
            ->assertJsonPath('data.affected_score_percent', 68.5)
            ->assertJsonPath('data.fire_risk_prediction_id', $prediction->id)
            ->assertJsonPath('data.risk_areas', [])
            ->assertJsonPath('data.status', Alert::STATUS_DRAFT);
    }

    public function test_only_system_administrators_and_mdrrmo_can_publish_a_draft_immediately(): void
    {
        $creator = $this->makeUser(UserRole::AlertOperator->value, ['email' => 'operator@example.com']);
        $publisher = $this->makeUser(UserRole::MdrrmoPersonnel->value, ['email' => 'manager@example.com']);
        $administrator = $this->makeUser(UserRole::SystemAdministrator->value, ['email' => 'admin@example.com']);
        $sourceAuthor = $this->makeUser(UserRole::BfpOfficer->value, ['email' => 'bfp@example.com']);
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'email' => 'recipient@example.com',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
        ]);
        $creatorToken = $this->tokenFor($creator);
        $publisherToken = $this->tokenFor($publisher);
        $administratorToken = $this->tokenFor($administrator);
        $this->clearJwtRequestState();
        $source = $this->makeVerifiedSource($sourceAuthor, $publisher);
        $area = $this->makeRiskArea($publisher);

        $alert = $this->withToken($creatorToken)->postJson('/api/v1/alerts', [
            'hazard_source_id' => $source->id,
            'title' => 'Immediate publishing test',
            'message' => 'A test advisory.',
            'safety_instructions' => 'Follow local guidance.',
            'severity' => 'warning',
            'starts_at' => now()->toIso8601String(),
            'risk_area_ids' => [$area->id],
        ])
            ->assertCreated()
            ->json('data');

        $this->withToken($creatorToken)
            ->patchJson("/api/v1/alerts/{$alert['id']}/publish")
            ->assertStatus(409);

        $this->clearJwtRequestState();
        $this->withToken($publisherToken)
            ->patchJson("/api/v1/alerts/{$alert['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_PUBLISHED);

        $this->assertDatabaseHas('alerts', [
            'id' => $alert['id'],
            'status' => Alert::STATUS_PUBLISHED,
            'approved_by' => null,
        ]);
        $this->assertDatabaseHas('alert_recipients', [
            'alert_id' => $alert['id'],
            'user_id' => $resident->id,
        ]);

        $this->clearJwtRequestState();
        $secondAlert = $this->withToken($creatorToken)->postJson('/api/v1/alerts', [
            'hazard_source_id' => $source->id,
            'title' => 'Immediate publishing test by admin',
            'message' => 'A second test advisory.',
            'safety_instructions' => 'Follow local guidance.',
            'severity' => 'warning',
            'starts_at' => now()->toIso8601String(),
            'risk_area_ids' => [$area->id],
        ])
            ->assertCreated()
            ->json('data');

        $this->clearJwtRequestState();
        $this->withToken($administratorToken)
            ->patchJson("/api/v1/alerts/{$secondAlert['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_PUBLISHED);
    }

    public function test_resident_api_exposes_only_matched_alerts_and_marks_opened_alerts_read(): void
    {
        $operator = $this->makeUser(UserRole::AlertOperator->value);
        $source = $this->makeVerifiedSource(
            $this->makeUser(UserRole::BfpOfficer->value),
            $this->makeUser(UserRole::MdrrmoPersonnel->value),
        );
        $resident = $this->makeUser(UserRole::CommunityResident->value);
        $otherResident = $this->makeUser(UserRole::CommunityResident->value);
        $area = $this->makeRiskArea($operator);
        $alert = Alert::create([
            'hazard_source_id' => $source->id,
            'title' => 'Current community warning',
            'message' => 'A current alert for matched residents.',
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
        $alert->recipients()->create([
            'user_id' => $resident->id,
            'delivered_at' => now(),
        ]);
        $area->update(['name' => 'Updated area name']);

        $residentToken = $this->tokenFor($resident);

        $this->withToken($residentToken)
            ->getJson('/api/v1/my/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.alert.id', $alert->id);

        $this->withToken($residentToken)
            ->getJson("/api/v1/my/alerts/{$alert->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $alert->id)
            ->assertJsonPath('data.risk_areas.0.name', 'Bongabong Central')
            ->assertJsonMissingPath('data.creator')
            ->assertJsonMissingPath('data.approver')
            ->assertJsonMissingPath('data.supersedes');

        $this->assertNotNull($alert->recipients()->where('user_id', $resident->id)->firstOrFail()->read_at);

        $this->withToken($this->tokenFor($otherResident))
            ->getJson("/api/v1/my/alerts/{$alert->id}")
            ->assertNotFound();
    }

    public function test_risk_area_api_accepts_geojson_and_validates_filter_and_sort_inputs(): void
    {
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $token = $this->tokenFor($manager);
        $boundary = [
            'type' => 'Polygon',
            'coordinates' => [[
                [121.3, 12.7],
                [121.4, 12.7],
                [121.4, 12.8],
                [121.3, 12.8],
                [121.3, 12.7],
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/v1/risk-areas', [
            'name' => 'Riverside',
            'barangay' => 'Poblacion',
            'classification' => 'Flood-prone',
            'boundary' => $boundary,
            'latitude' => 12.75,
            'longitude' => 121.35,
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.boundary.type', 'Polygon')
            ->assertJsonPath('data.latitude', 12.75)
            ->assertJsonPath('data.longitude', 121.35);

        $this->assertDatabaseHas('risk_areas', [
            'name' => 'Riverside',
            'barangay' => 'Poblacion',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/risk-areas?barangay=Poblacion&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Riverside');

        $this->withToken($token)
            ->getJson('/api/v1/risk-areas?sort=name%20DESC')
            ->assertUnprocessable();

        $areaId = $response->json('data.id');
        $this->withToken($token)
            ->patchJson("/api/v1/risk-areas/{$areaId}", ['classification' => 'Landslide-prone'])
            ->assertOk()
            ->assertJsonPath('data.classification', 'Landslide-prone')
            ->assertJsonPath('data.name', 'Riverside');

        $this->withToken($token)
            ->patchJson("/api/v1/risk-areas/{$areaId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withToken($token)
            ->getJson('/api/v1/risk-areas?is_active=false')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Riverside');

        $this->withToken($token)
            ->postJson('/api/v1/risk-areas', [
                'name' => 'Missing coordinates',
                'barangay' => 'Poblacion',
                'classification' => 'Flood-prone',
                'boundary' => $boundary,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->withToken($token)
            ->postJson('/api/v1/risk-areas', [
                'name' => 'Outside center point',
                'barangay' => 'Poblacion',
                'classification' => 'Flood-prone',
                'boundary' => $boundary,
                'latitude' => 12.9,
                'longitude' => 121.35,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');

        $bfpOfficer = $this->makeUser(UserRole::BfpOfficer->value);
        $this->withToken($this->tokenFor($bfpOfficer))
            ->postJson('/api/v1/risk-areas', [])
            ->assertForbidden();
    }

    public function test_hazard_source_submission_and_independent_review_are_available_through_the_api(): void
    {
        $officer = $this->makeUser(UserRole::BfpOfficer->value);
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);

        $response = $this->withToken($this->tokenFor($officer))->postJson('/api/v1/hazard-sources', [
            'source_type' => 'fire',
            'reference' => 'BFP-API-001',
            'title' => 'Confirmed local fire information',
            'details' => 'Information confirmed by the local BFP office.',
            'issued_at' => now()->subMinutes(5)->toIso8601String(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending');

        $sourceId = $response->json('data.id');

        $this->withToken($this->tokenFor($officer))
            ->patchJson("/api/v1/hazard-sources/{$sourceId}/review", ['decision' => 'verified'])
            ->assertForbidden();

        $this->withToken($this->tokenFor($manager))
            ->patchJson("/api/v1/hazard-sources/{$sourceId}/review", ['decision' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_profile_api_exposes_private_location_only_to_the_owner_and_updates_password_safely(): void
    {
        $resident = $this->makeUser(UserRole::CommunityResident->value, [
            'email' => 'profile-resident@example.com',
            'name' => 'API Resident',
            'barangay' => 'Poblacion',
            'location_latitude' => 12.73,
            'location_longitude' => 121.36,
        ]);
        $token = $this->tokenFor($resident);

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonMissingPath('data.location_latitude');

        $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.location_latitude', 12.73)
            ->assertJsonPath('data.location_longitude', 121.36);

        $this->withToken($token)
            ->patchJson('/api/v1/profile', [
                'name' => 'Updated Resident',
                'email' => 'profile-resident@example.com',
                'barangay' => 'Masaguisi',
                'sitio' => 'Purok 2',
                'location_latitude' => 12.74,
                'location_longitude' => 121.37,
                'current_password' => 'WrongPassword123',
                'password' => 'UpdatedPassword123',
                'password_confirmation' => 'UpdatedPassword123',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

        $this->assertDatabaseHas('users', [
            'id' => $resident->id,
            'name' => 'API Resident',
            'barangay' => 'Poblacion',
        ]);

        $this->withToken($token)
            ->patchJson('/api/v1/profile', [
                'name' => 'Updated Resident',
                'email' => 'profile-resident@example.com',
                'barangay' => 'Masaguisi',
                'sitio' => 'Purok 2',
                'location_latitude' => 12.74,
                'location_longitude' => 121.37,
                'current_password' => 'TestPassword123',
                'password' => 'UpdatedPassword123',
                'password_confirmation' => 'UpdatedPassword123',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Resident')
            ->assertJsonPath('data.location_latitude', 12.74);

        $this->assertDatabaseHas('users', [
            'id' => $resident->id,
            'name' => 'Updated Resident',
            'barangay' => 'Masaguisi',
        ]);
        $this->assertTrue(Hash::check('UpdatedPassword123', $resident->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $resident->id,
            'action' => 'profile.updated',
            'subject_id' => $resident->id,
        ]);
    }

    public function test_audit_history_keeps_authentication_events_and_hides_routine_page_views(): void
    {
        $administrator = $this->makeUser(UserRole::SystemAdministrator->value);
        $token = $this->tokenFor($administrator);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $administrator->id,
            'action' => 'auth.signed_in',
        ]);

        AuditLog::create([
            'actor_id' => $administrator->id,
            'action' => 'system.viewed',
            'subject_type' => 'api.v1.staff.index',
            'metadata' => ['route' => 'api.v1.staff.index', 'method' => 'GET', 'status' => 200],
            'created_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/staff/audit')
            ->assertOk()
            ->assertJsonMissing(['action' => 'system.viewed']);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $administrator->id,
            'action' => 'auth.signed_out',
        ]);
    }

    public function test_fire_incident_records_allow_independent_bfp_review_and_only_verified_cases_export(): void
    {
        $submitter = $this->makeUser(UserRole::BfpOfficer->value);
        $reviewer = $this->makeUser(UserRole::BfpOfficer->value);
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $resident = $this->makeUser(UserRole::CommunityResident->value, ['barangay' => 'Poblacion']);
        $residentInAnotherBarangay = $this->makeUser(UserRole::CommunityResident->value, ['barangay' => 'Anilao']);
        $submitterToken = $this->tokenFor($submitter);
        $this->clearJwtRequestState();
        $reviewerToken = $this->tokenFor($reviewer);
        $this->clearJwtRequestState();
        $managerToken = $this->tokenFor($manager);
        $this->clearJwtRequestState();
        $residentToken = $this->tokenFor($resident);
        $this->clearJwtRequestState();
        $otherResidentToken = $this->tokenFor($residentInAnotherBarangay);
        $this->clearJwtRequestState();

        $incidentData = [
            'occurred_at' => now()->subDays(30)->toIso8601String(),
            'barangay' => 'Poblacion',
            'sitio' => 'Purok 1',
            'incident_type' => 'structure',
            'cause' => 'electrical',
            'latitude' => 12.73,
            'longitude' => 121.36,
            'injuries' => 1,
            'fatalities' => 0,
            'property_damage_estimate' => 1200.50,
            'source_reference' => 'BFP-2025-001',
            'details' => 'Historical incident recorded from the official BFP report.',
        ];

        $created = $this->withToken($submitterToken)
            ->postJson('/api/v1/fire-incidents', $incidentData)
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending');
        $incidentId = $created->json('data.id');

        $this->withToken($submitterToken)
            ->patchJson("/api/v1/fire-incidents/{$incidentId}/review", [
                'decision' => 'rejected',
                'review_notes' => 'The submitter cannot reject their own record.',
            ])
            ->assertForbidden();

        $this->clearJwtRequestState();
        $this->withToken($managerToken)
            ->patchJson("/api/v1/fire-incidents/{$incidentId}/review", ['decision' => 'verified'])
            ->assertForbidden();

        $this->clearJwtRequestState();
        $this->withToken($reviewerToken)
            ->patchJson("/api/v1/fire-incidents/{$incidentId}/review", ['decision' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.reviewer.id', $reviewer->id);

        $this->clearJwtRequestState();
        $residentIncidents = $this->withToken($residentToken)
            ->getJson('/api/v1/my/fire-incidents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incidentId)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('notifications.0.fire_incident_id', $incidentId)
            ->assertJsonMissing(['source_reference' => 'BFP-2025-001']);
        $notificationId = $residentIncidents->json('notifications.0.id');

        $this->withToken($residentToken)
            ->patchJson("/api/v1/my/fire-incidents/notifications/{$notificationId}/read")
            ->assertOk();

        $this->withToken($residentToken)
            ->getJson('/api/v1/my/fire-incidents')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->clearJwtRequestState();
        $this->withToken($otherResidentToken)
            ->getJson('/api/v1/my/fire-incidents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incidentId)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('notifications.0.fire_incident_id', $incidentId);

        $this->clearJwtRequestState();
        $pending = $this->withToken($managerToken)
            ->postJson('/api/v1/fire-incidents', [
                ...$incidentData,
                'source_reference' => 'BFP-2025-002',
            ])
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending');
        $pendingId = $pending->json('data.id');

        $this->clearJwtRequestState();
        $this->withToken($reviewerToken)
            ->patchJson("/api/v1/fire-incidents/{$pendingId}/review", ['decision' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->clearJwtRequestState();
        $this->withToken($reviewerToken)
            ->patchJson("/api/v1/fire-incidents/{$pendingId}/review", [
                'decision' => 'rejected',
                'review_notes' => 'The supplied source record could not be verified.',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->clearJwtRequestState();
        $this->withToken($managerToken)
            ->getJson('/api/v1/fire-incidents/training-data')
            ->assertOk()
            ->assertJsonPath('meta.verified_records', 1)
            ->assertJsonPath('data.0.fire_occurred', 1)
            ->assertJsonPath('data.0.source_reference', 'BFP-2025-001')
            ->assertJsonMissing(['source_reference' => 'BFP-2025-002']);

        $this->clearJwtRequestState();
        $this->withToken($residentToken)
            ->getJson('/api/v1/fire-incidents')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $submitter->id,
            'action' => 'fire_incident.submitted',
            'subject_id' => $incidentId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $reviewer->id,
            'action' => 'fire_incident.verified',
            'subject_id' => $incidentId,
        ]);
    }

    public function test_bfp_submitter_can_verify_their_own_fire_incident(): void
    {
        $submitter = $this->makeUser(UserRole::BfpOfficer->value);
        $submitterToken = $this->tokenFor($submitter);
        $incident = $this->withToken($submitterToken)
            ->postJson('/api/v1/fire-incidents', [
                'occurred_at' => now()->subDay()->toIso8601String(),
                'barangay' => 'Poblacion',
                'incident_type' => 'structure',
                'cause' => 'electrical',
                'latitude' => 12.73,
                'longitude' => 121.36,
                'source_reference' => 'BFP-SELF-VERIFY-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending');
        $incidentId = $incident->json('data.id');

        $this->clearJwtRequestState();
        $this->withToken($submitterToken)
            ->patchJson("/api/v1/fire-incidents/{$incidentId}/review", ['decision' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.reviewer.id', $submitter->id);
    }

    public function test_staff_api_restricts_management_and_records_staff_changes(): void
    {
        $administrator = $this->makeUser(UserRole::SystemAdministrator->value, [
            'email' => 'staff-admin@example.com',
        ]);
        $administratorToken = $this->tokenFor($administrator);
        $resident = $this->makeUser(UserRole::CommunityResident->value);

        $this->withToken($this->tokenFor($resident))
            ->getJson('/api/v1/staff')
            ->assertForbidden();

        $this->clearJwtRequestState();
        $created = $this->withToken($administratorToken)
            ->postJson('/api/v1/staff', [
                'name' => 'New MDRRMO Member',
                'email' => 'new-mdrrmo@example.com',
                'role' => UserRole::MdrrmoPersonnel->value,
                'password' => 'TemporaryStaff123',
                'password_confirmation' => 'TemporaryStaff123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', UserRole::MdrrmoPersonnel->value)
            ->assertJsonPath('data.is_active', true);

        $staffId = $created->json('data.id');

        $this->withToken($administratorToken)
            ->patchJson("/api/v1/staff/{$staffId}/role", ['role' => UserRole::AlertOperator->value])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::AlertOperator->value);

        $this->withToken($administratorToken)
            ->patchJson("/api/v1/staff/{$staffId}/active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withToken($administratorToken)
            ->getJson('/api/v1/staff/audit')
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $administrator->id);

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'role' => UserRole::AlertOperator->value,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $administrator->id,
            'action' => 'staff.deactivated',
            'subject_id' => $staffId,
        ]);
    }

    public function test_authorized_staff_can_request_a_fire_occurrence_decision_support_estimate(): void
    {
        $manager = $this->makeUser(UserRole::MdrrmoPersonnel->value);
        $bfpOfficer = $this->makeUser(UserRole::BfpOfficer->value);
        $resident = $this->makeUser(UserRole::CommunityResident->value);
        $managerToken = $this->tokenFor($manager);
        $this->clearJwtRequestState();
        $bfpToken = $this->tokenFor($bfpOfficer);
        $this->clearJwtRequestState();
        $residentToken = $this->tokenFor($resident);
        $this->clearJwtRequestState();

        $this->withToken($managerToken)
            ->getJson('/api/v1/fire-risk/model-info')
            ->assertOk()
            ->assertJsonPath('data.model_version', 'mdrrmo-bongabong-barangay-500m-24h-v4')
            ->assertJsonPath('data.model_status', 'staff_decision_support')
            ->assertJsonPath('data.forecast_horizon', 'next_24_hours')
            ->assertJsonPath('data.prediction_radius_meters', 500)
            ->assertJsonPath('data.outcome_scope', 'verified fire occurrence within 500 meters of a recorded observation point in the selected barangay during the next 24 hours')
            ->assertJsonMissingPath('data.numeric_ranges.latitude')
            ->assertJsonMissingPath('data.numeric_ranges.longitude')
            ->assertJsonPath('data.training_rows', 1200)
            ->assertJsonPath('data.validation.strategy', 'walk_forward_calendar_years')
            ->assertJsonCount(3, 'data.validation.folds')
            ->assertJsonPath('data.tuning.selected_l2_strength', 0.01);

        $input = [
            'month' => 1,
            'elevation_m' => 48,
            'temperature_c' => 25,
            'relative_humidity_pct' => 70.5,
            'wind_speed_kmh' => 25.1,
            'rainfall_mm' => 0,
            'days_since_last_rain' => 5,
            'soil_moisture_pct' => 64.9,
            'fuel_dryness_index' => 36.7,
            'dist_to_fire_station_km' => 4.61,
            'distance_to_water_km' => 3.5,
            'barangay' => 'Polusahi',
            'land_use_zone' => 'Grassland_Agroforestry',
            'season' => 'Cool_Amihan_Transition',
            'latitude' => 12.73,
            'longitude' => 121.36,
        ];

        $this->withToken($managerToken)
            ->postJson('/api/v1/fire-risk/predict', [
                ...$input,
                'temperature_c' => 1000,
                'barangay' => 'Unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['temperature_c', 'barangay']);

        $this->withToken($managerToken)
            ->postJson('/api/v1/fire-risk/predict', [
                ...$input,
                'latitude' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');

        $prediction = $this->withToken($managerToken)
            ->postJson('/api/v1/fire-risk/predict', $input)
            ->assertOk()
            ->assertJsonPath('data.model_version', 'mdrrmo-bongabong-barangay-500m-24h-v4')
            ->assertJsonPath('data.threshold', 0.5)
            ->assertJsonStructure([
                'data' => ['prediction_id', 'prediction', 'threshold_met', 'score_percent', 'disclaimer'],
            ]);

        $this->assertGreaterThanOrEqual(0, $prediction->json('data.score_percent'));
        $this->assertLessThanOrEqual(100, $prediction->json('data.score_percent'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action' => 'fire_risk.predicted',
        ]);

        $this->assertDatabaseHas('fire_risk_predictions', [
            'barangay' => 'Polusahi',
            'latitude' => 12.73,
            'longitude' => 121.36,
            'score_percent' => $prediction->json('data.score_percent'),
            'model_version' => 'mdrrmo-bongabong-barangay-500m-24h-v4',
            'created_by' => $manager->id,
        ]);

        $this->clearJwtRequestState();
        $this->withToken($managerToken)
            ->getJson('/api/v1/fire-risk/predictions')
            ->assertOk()
            ->assertJsonPath('data.0.barangay', 'Polusahi')
            ->assertJsonPath('data.0.score_percent', $prediction->json('data.score_percent'))
            ->assertJsonPath('meta.window_hours', 24);

        $this->clearJwtRequestState();
        $this->withToken($bfpToken)
            ->getJson('/api/v1/fire-risk/predictions')
            ->assertOk()
            ->assertJsonPath('data.0.barangay', 'Polusahi');

        $this->clearJwtRequestState();
        $this->withToken($residentToken)
            ->getJson('/api/v1/fire-risk/model-info')
            ->assertForbidden();

        $this->withToken($residentToken)
            ->getJson('/api/v1/fire-risk/predictions')
            ->assertForbidden();
    }

    public function test_web_page_paths_redirect_to_the_separate_frontend_and_preserve_role_access(): void
    {
        config(['services.frontend.url' => 'http://localhost:5173']);

        foreach (['/', '/login', '/register'] as $path) {
            $this->get($path)
                ->assertRedirect('http://localhost:5173'.$path);
        }

        $administrator = $this->makeUser(UserRole::SystemAdministrator->value);
        foreach ([
            '/dashboard',
            '/profile',
            '/risk-areas',
            '/risk-areas/create',
            '/risk-areas/12/edit',
            '/hazard-sources',
            '/hazard-sources/create',
            '/hazard-sources/12/review',
            '/alerts',
            '/alerts/create',
            '/alerts/12',
            '/alerts/12/edit',
            '/staff',
            '/staff/create',
            '/staff/audit',
            '/fire-incidents',
            '/fire-risk',
        ] as $path) {
            $this->actingAs($administrator)
                ->get($path)
                ->assertRedirect('http://localhost:5173'.$path);
        }

        $resident = $this->makeUser(UserRole::CommunityResident->value);
        foreach (['/dashboard', '/profile', '/my-alerts'] as $path) {
            $this->actingAs($resident)
                ->get($path)
                ->assertRedirect('http://localhost:5173'.$path);
        }

        $this->actingAs($resident)
            ->get('/alerts/create')
            ->assertForbidden();
        $this->actingAs($resident)
            ->get('/fire-incidents')
            ->assertForbidden();
        $this->actingAs($resident)
            ->get('/fire-risk')
            ->assertForbidden();

        $this->get('/not-a-page')->assertNotFound();
    }

    public function test_api_allows_the_configured_frontend_origin_to_make_authenticated_requests(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Methods');

        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173')
            ->assertHeader('Access-Control-Allow-Methods');
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('TestPassword123'),
            ...$attributes,
        ]);
    }

    private function makeVerifiedSource(User $creator, User $verifier): HazardSource
    {
        return HazardSource::create([
            'source_type' => 'weather',
            'organization' => 'PAGASA',
            'title' => 'Official weather advisory',
            'details' => 'Official source details.',
            'issued_at' => now()->subMinutes(10),
            'verification_status' => 'verified',
            'created_by' => $creator->id,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);
    }

    private function makeRiskArea(User $creator): RiskArea
    {
        return RiskArea::create([
            'name' => 'Bongabong Central',
            'barangay' => 'Bongabong',
            'sitio' => 'Purok 1',
            'classification' => 'Flood-prone',
            'latitude' => 12.75,
            'longitude' => 121.35,
            'boundary' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [121.3, 12.7],
                    [121.4, 12.7],
                    [121.4, 12.8],
                    [121.3, 12.8],
                    [121.3, 12.7],
                ]],
            ],
            'is_active' => true,
            'created_by' => $creator->id,
        ]);
    }

    private function tokenFor(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'TestPassword123',
        ]);

        $response->assertOk();

        return $response->json('access_token');
    }

    private function clearJwtRequestState(): void
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->app->forgetInstance('tymon.jwt');
    }
}
