<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): string
    {
        return $user->createToken('test', ['*'])->plainTextToken;
    }

    public function test_admin_sees_full_patient_identifiers(): void
    {
        $patient = Patient::factory()->create([
            'cpr' => '980712345',
            'phone' => '+97331234567',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->withToken($this->bearer($admin))
            ->getJson('/api/patients?search='.$patient->mrn)
            ->assertOk()
            ->assertJsonPath('data.0.cpr', '980712345')
            ->assertJsonPath('data.0.phone', '+97331234567');
    }

    public function test_staff_sees_masked_patient_identifiers(): void
    {
        $patient = Patient::factory()->create([
            'cpr' => '980712345',
            'phone' => '+97331234567',
        ]);
        $staff = User::factory()->create(['role' => UserRole::ClinicUser]);

        $this->withToken($this->bearer($staff))
            ->getJson('/api/patients?search='.$patient->mrn)
            ->assertOk()
            ->assertJsonPath('data.0.cpr', '*****2345')
            ->assertJsonPath('data.0.phone', '********4567');
    }

    public function test_visits_filter_by_branch_and_cursor_without_duplicates(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $doctorA = Doctor::factory()->for($branchA)->create();
        $doctorB = Doctor::factory()->for($branchB)->create();
        $patient = Patient::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::ClinicUser]);
        $staff->branches()->attach($branchA);
        $a = Visit::factory()->for($patient)->for($doctorA)->for($branchA)->create(['visit_date' => '2026-01-15 09:00:00']);
        $b = Visit::factory()->for($patient)->for($doctorA)->for($branchA)->create(['visit_date' => '2026-01-15 09:00:00']);
        Visit::factory()->for($patient)->for($doctorB)->for($branchB)->create();
        $token = $this->bearer($staff);

        $first = $this->withToken($token)->getJson('/api/visits?per_page=1');
        $first->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $cursor = $first->json('meta.next_cursor');
        $second = $this->withToken($token)->getJson('/api/visits?per_page=1&cursor='.urlencode($cursor));
        $second->assertOk()->assertJsonPath('data.0.id', $a->id);
        $this->withToken($token)->getJson('/api/visits?branch_id='.$branchB->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_create_replay_and_revenue_use_committed_integer_totals(): void
    {
        $branch = Branch::factory()->create();
        $doctor = Doctor::factory()->for($branch)->create();
        $patient = Patient::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $token = $this->bearer($admin);
        $payload = [
            'patient_id' => $patient->id, 'branch_id' => $branch->id, 'doctor_id' => $doctor->id,
            'visit_date' => '2026-01-15 09:00:00', 'status' => 'completed',
            'discount' => '0.500',
            'items' => [['service_code' => 'CONSULT', 'qty' => 1, 'unit_price' => '10.000']],
        ];
        $key = 'test-key-1234567890';
        $created = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson('/api/visits', $payload);
        $created->assertCreated()->assertJsonPath('data.invoice_total', '10.450');
        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson('/api/visits', $payload);
        $replay->assertCreated()->assertExactJson($created->json());
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $report = $this->withToken($token)->getJson('/api/reports/revenue?from=2026-01-01&to=2026-01-31&group_by=branch');
        $report->assertOk()->assertJsonPath('data.0.net', '10.450')->assertJsonPath('data.0.outstanding', '10.450');
        $this->withToken($token)
            ->getJson('/api/reports/revenue?from=2025-01-01&to=2026-12-31&group_by=branch')
            ->assertOk()
            ->assertJsonPath('data.0.net', '10.450');
    }

    public function test_changed_idempotency_payload_conflicts_without_another_visit(): void
    {
        $branch = Branch::factory()->create();
        $doctor = Doctor::factory()->for($branch)->create();
        $patient = Patient::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $token = $this->bearer($admin);
        $payload = [
            'patient_id' => $patient->id, 'branch_id' => $branch->id, 'doctor_id' => $doctor->id,
            'visit_date' => '2026-01-15 09:00:00', 'status' => 'completed', 'discount' => '0.000',
            'items' => [['service_code' => 'CONSULT', 'qty' => 1, 'unit_price' => '10.000']],
        ];
        $key = 'test-key-1234567890';
        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson('/api/visits', $payload)->assertCreated();
        $payload['items'][0]['unit_price'] = '12.000';
        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson('/api/visits', $payload)->assertStatus(409);
        $this->assertDatabaseCount('visits', 1);
    }
}
