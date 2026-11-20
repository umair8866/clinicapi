<?php

namespace Tests\Feature;

use App\Jobs\ExportVisits;
use App\Jobs\ImportPatients;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Export;
use App\Models\Import;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitQueryService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransferJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_retry_keeps_one_csv_and_committed_progress(): void
    {
        Storage::fake('s3');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $branch = Branch::factory()->create();
        $doctor = Doctor::factory()->for($branch)->create();
        $patient = Patient::factory()->create();
        $visit = Visit::factory()->for($patient)->for($doctor)->for($branch)->create();
        $export = Export::create([
            'user_id' => $admin->id, 'filters' => [], 'branch_scope' => [], 'status' => 'queued',
            'max_visit_id' => $visit->id, 'object_key' => 'exports/test/visits.csv',
        ]);
        $job = new ExportVisits($export->id);
        $job->handle(new VisitQueryService());
        $job->handle(new VisitQueryService());
        $this->assertSame('done', $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->rows_processed);
        $this->assertCount(2, str_getcsv(Storage::disk('s3')->get($export->object_key), "\n"));
    }

    public function test_import_replay_upserts_mrn_and_reports_bad_rows_without_values(): void
    {
        Storage::fake('s3');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $csv = "mrn,cpr,name,phone,dob,gender\nMRN-1,CPR-1,Alice,+97330000001,1990-01-01,female\nMRN-2,CPR-1,Bob,+97330000002,1991-01-01,male\n";
        Storage::disk('s3')->put('imports/test/source.csv', $csv);
        $import = Import::create(['user_id' => $admin->id, 'status' => 'queued',
            'object_key' => 'imports/test/source.csv', 'source_bytes' => strlen($csv)]);
        $job = new ImportPatients($import->id);
        $job->handle();
        $job->handle();
        $this->assertSame('done', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->valid_rows);
        $this->assertSame(1, $import->fresh()->invalid_rows);
        $this->assertDatabaseCount('patients', 1);
        $report = Storage::disk('s3')->get($import->fresh()->error_object_key);
        $this->assertStringContainsString('Duplicate identifier in chunk', $report);
        $this->assertStringNotContainsString('Bob', $report);
    }
}
