<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Policies\VisitPolicy;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_patient_history_is_scoped_to_current_branch_assignments(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $patient = Patient::factory()->create();
        $doctorA = Doctor::factory()->for($branchA)->create();
        $doctorB = Doctor::factory()->for($branchB)->create();
        $visitA = Visit::factory()->for($patient)->for($doctorA)->for($branchA)->create();
        $visitB = Visit::factory()->for($patient)->for($doctorB)->for($branchB)->create();
        $staff = User::factory()->create(['role' => UserRole::ClinicUser, 'is_active' => true]);
        $staff->branches()->attach($branchA);

        $this->assertSame([$visitA->id], $patient->visits()->visibleTo($staff)->pluck('visits.id')->all());
        $policy = new VisitPolicy();
        $this->assertTrue($policy->view($staff, $visitA)->allowed());
        $this->assertSame(404, $policy->view($staff, $visitB)->status());
        $this->assertFalse($policy->create($staff, $branchB));

        $staff->branches()->attach($branchB);
        $this->assertTrue($policy->create($staff, $branchB));
        $this->assertCount(2, $patient->visits()->visibleTo($staff)->get());

        $staff->branches()->detach($branchA);
        $this->assertSame([$visitB->id], $patient->visits()->visibleTo($staff)->pluck('visits.id')->all());

        $staff->is_active = false;
        $this->assertCount(0, $patient->visits()->visibleTo($staff)->get());
    }
}
