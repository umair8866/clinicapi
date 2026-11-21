<?php

namespace Tests\Feature;

use App\Services\ClinicSeedService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ClinicSeedResumeTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSeedDatabase;
    }

    public function refreshDatabase(): void
    {
        if (getenv('RUN_SEED_INTEGRATION_TESTS') !== '1'
            || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! preg_match('/_tests?$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Explicit opt-in and a dedicated MySQL/MariaDB database ending in _test or _tests required.');
        }
        $this->refreshSeedDatabase();
    }

    public function test_stopped_run_resumes_and_completed_run_does_not_duplicate_rows(): void
    {
        $service = new ClinicSeedService();
        $options = ['patients' => 10, 'visits' => 50, 'batch' => 1000, 'seed' => 1729];
        $progress = static function (array $state): void {
        };
        $first = $service->run($options, 3, $progress);
        $this->assertSame('visits', $first['phase']);
        $this->assertSame(0, DB::table('visits')->count());
        $frozen = DB::table('seed_runs')->value('config');
        $result = $service->run($options, 0, $progress);
        $this->assertSame('done', $result['status']);
        $this->assertSame($frozen, DB::table('seed_runs')->value('config'));
        $this->assertSame(50, DB::table('visits')->count());
        $this->assertSame(150, DB::table('invoice_items')->count());
        $this->assertSame(40, DB::table('payments')->count());
        $this->assertGreaterThan(0, $result['metrics']['verification']['cross_branch_patients']);
        $this->assertFalse($result['metrics']['under_30_minutes']);
        $again = $service->run($options, 0, $progress);
        $this->assertSame($result, $again);
    }

    public function test_large_batch_inserts_child_rows_in_bounded_statements(): void
    {
        $result = (new ClinicSeedService())->run(
            ['patients' => 100, 'visits' => 1500, 'batch' => 5000, 'seed' => 1729],
            0,
            static function (array $state): void {
            },
        );

        $this->assertSame('done', $result['status']);
        $this->assertDatabaseCount('visits', 1500);
        $this->assertDatabaseCount('invoice_items', 4500);
        $this->assertDatabaseCount('payments', 1200);
    }

    public function test_failure_during_batch_rolls_back_rows_summaries_and_checkpoint(): void
    {
        $service = new ClinicSeedService();
        $options = ['patients' => 10, 'visits' => 50, 'batch' => 1000, 'seed' => 1729];
        $progress = static function (array $state): void {
        };
        $service->run($options, 3, $progress);
        $inject = true;
        DB::listen(function (QueryExecuted $event) use (&$inject): void {
            if ($inject && str_contains(strtolower($event->sql), 'insert into patient_year_totals')) {
                $inject = false;
                throw new RuntimeException('Injected failure after summary write.');
            }
        });
        try {
            $service->run($options, 0, $progress);
            $this->fail('Injected failure must interrupt the run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after summary write.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('visits')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('daily_branch_revenue')->count());
        $this->assertSame(0, (int) DB::table('seed_runs')->value('last_committed_id'));
        $result = $service->run($options, 0, $progress);
        $this->assertSame('done', $result['status']);
        $this->assertSame(40, DB::table('payments')->count());
    }

    public function test_changed_options_are_rejected_without_resetting_progress(): void
    {
        $service = new ClinicSeedService();
        $options = ['patients' => 10, 'visits' => 50, 'batch' => 1000, 'seed' => 1729];
        $progress = static function (array $state): void {
        };
        $service->run($options, 1, $progress);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Seed parameters changed');
        $service->run(array_replace($options, ['visits' => 60]), 0, $progress);
    }

    public function test_existing_business_rows_are_never_overwritten(): void
    {
        \App\Models\Branch::factory()->create();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Seed target must be empty');
        (new ClinicSeedService())->run(['patients' => 10, 'visits' => 50, 'batch' => 1000, 'seed' => 1729], 0, static function (array $state): void {
        });
    }

    public function test_another_database_session_cannot_seed_concurrently(): void
    {
        $connection = DB::connection();
        config(['database.connections.seed_contender' => config('database.connections.'.config('database.default'))]);
        $contender = DB::connection('seed_contender');
        $lock = 'clinic-seed:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 40);
        $contender->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another seed command owns this database.');
            (new ClinicSeedService())->run(['patients' => 10, 'visits' => 50, 'batch' => 1000, 'seed' => 1729], 0, static function (array $state): void {
            });
        } finally {
            $contender->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
            DB::purge('seed_contender');
        }
    }
}
