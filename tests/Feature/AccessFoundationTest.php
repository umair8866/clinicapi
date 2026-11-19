<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Policies\VisitPolicy;
use App\UserRole;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AccessFoundationTest extends TestCase
{
    public function test_guest_cannot_read_account(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_session_without_bearer_token_cannot_read_api_account(): void
    {
        $user = User::factory()->make(['is_active' => true, 'role' => UserRole::Admin]);
        $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_bearer_header_does_not_turn_session_into_api_token(): void
    {
        $user = User::factory()->make(['is_active' => true, 'role' => UserRole::Admin]);
        $this->actingAs($user, 'sanctum')->withToken('invalid')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_disabled_account_cannot_read_api_account(): void
    {
        $user = User::factory()->make(['is_active' => false, 'role' => UserRole::Admin]);
        $user->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]));
        $this->actingAs($user, 'sanctum')->withToken('test')->getJson('/api/user')->assertForbidden();
    }

    public function test_account_response_omits_email_and_credentials(): void
    {
        $user = User::factory()->make(['id' => 10, 'name' => 'Reviewer', 'is_active' => true, 'role' => UserRole::Admin]);
        $user->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]));
        $this->actingAs($user, 'sanctum')->withToken('test')->getJson('/api/user')
            ->assertOk()->assertExactJson(['data' => ['id' => 10, 'name' => 'Reviewer', 'role' => 'admin']]);
    }

    public function test_admin_token_abilities_restrict_visit_access(): void
    {
        $user = User::factory()->make(['is_active' => true, 'role' => UserRole::Admin]);
        $user->withAccessToken(new PersonalAccessToken(['abilities' => ['patients:read']]));
        $visit = new Visit(['branch_id' => 1]);
        $response = (new VisitPolicy())->view($user, $visit);
        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_active_admin_can_create_at_active_branch_only(): void
    {
        $user = User::factory()->make(['is_active' => true, 'role' => UserRole::Admin]);
        $branch = Branch::factory()->make(['id' => 1, 'is_active' => true]);
        $policy = new VisitPolicy();
        $this->assertTrue($policy->create($user, $branch));
        $branch->is_active = false;
        $this->assertFalse($policy->create($user, $branch));
        $branch->is_active = true;
        $user->is_active = false;
        $this->assertFalse($policy->create($user, $branch));
    }

    public function test_patient_serialization_omits_private_fields(): void
    {
        $patient = Patient::factory()->make(['flat_number' => '12', 'building_number' => '10', 'road_number' => '20', 'block' => '30', 'area' => 'Manama']);
        $this->assertSame(['mrn', 'name'], array_keys($patient->toArray()));
    }

    public function test_role_and_visit_creator_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'Staff', 'role' => 'admin', 'is_active' => true]);
        $visit = new Visit(['created_by' => 123]);
        $this->assertNull($user->role);
        $this->assertNull($user->is_active);
        $this->assertNull($visit->created_by);
    }
}
