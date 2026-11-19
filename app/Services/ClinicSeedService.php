<?php

namespace App\Services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ClinicSeedService
{
    private const SUMMARY_TABLES = ['daily_branch_revenue', 'daily_doctor_revenue', 'patient_year_totals'];

    /** @param array{patients: int, visits: int, batch: int, seed: int} $options
     * @param Closure(array<string, mixed>): void $progress
     * @return array<string, mixed>
     */
    public function run(array $options, int $maxBatches, Closure $progress): array
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Use a dedicated MySQL/MariaDB synthetic-data database.');
        }
        if ($options['batch'] < 1 || $options['batch'] > 1000 || $maxBatches < 0) {
            throw new RuntimeException('Batch must be 1..1000; max-batches must be nonnegative.');
        }
        $db->disableQueryLog();
        $lock = 'clinic-seed:'.substr(hash('sha256', $db->getDatabaseName()), 0, 40);
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired !== 1) {
            throw new RuntimeException('Another seed command owns this database.');
        }

        $run = null;
        $db->setReconnector(static function (): never {
            throw new RuntimeException('Seed connection lost. Rerun to resume with a new lock.');
        });
        try {
            $run = $this->openRun($db, $options);
            $config = json_decode($run->config, true, flags: JSON_THROW_ON_ERROR);
            $generator = new ClinicSeedGenerator($config['patients'], $config['visits'], $config['range_start'], $config['range_end'], $config['seed']);
            $state = $this->state($run, $generator);
            if ($run->status === 'done') {
                $progress($state);
                return $state;
            }
            $metrics = json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR);
            $metrics['attempts'] = ($metrics['attempts'] ?? 0) + 1;
            $db->table('seed_runs')->where('id', $run->id)->update(['status' => 'processing', 'error_code' => null, 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR)]);
            $progress($state);
            $batches = 0;
            while (true) {
                $started = hrtime(true);
                $state = $db->transaction(function () use ($db, $lock, $generator, $options, $run, $started): array {
                    $this->assertLock($db, $lock);
                    $current = $db->table('seed_runs')->where('id', $run->id)->lockForUpdate()->first();
                    $counts = json_decode($current->row_counts, true, flags: JSON_THROW_ON_ERROR);
                    $metrics = json_decode($current->metrics, true, flags: JSON_THROW_ON_ERROR);
                    $phase = $current->phase;
                    $last = (int) $current->last_committed_id;
                    $targets = $generator->targets();
                    if (in_array($phase, ['branches', 'doctors', 'patients'], true)) {
                        $end = min($last + $options['batch'], $targets[$phase]);
                        $method = ['branches' => 'branch', 'doctors' => 'doctor', 'patients' => 'patient'][$phase];
                        $rows = [];
                        for ($id = $last + 1; $id <= $end; $id++) {
                            $rows[] = $generator->$method($id);
                        }
                        $db->table($phase)->insert($rows);
                        $counts[$phase] = $end;
                    } elseif ($phase === 'visits') {
                        $end = min($last + $options['batch'], $generator->visits);
                        $rows = ['visits' => [], 'invoices' => [], 'invoice_items' => [], 'payments' => []];
                        for ($id = $last + 1; $id <= $end; $id++) {
                            foreach ($generator->visitBundle($id) as $table => $records) {
                                foreach ($records as $record) {
                                    $rows[$table][] = $record;
                                }
                            }
                        }
                        foreach ($rows as $table => $records) {
                            if ($records !== []) {
                                $db->table($table)->insert($records);
                            }
                            $counts[$table] += count($records);
                        }
                        $this->addSummaries($db, $last + 1, $end);
                    } elseif ($phase === 'validate') {
                        $end = min($last + $options['batch'], $generator->visits);
                        $this->validateBatch($db, $last + 1, $end, $generator);
                    } else {
                        throw new RuntimeException('Unknown seed phase.');
                    }
                    $elapsed = (int) ((hrtime(true) - $started) / 1000000);
                    $metrics['active_elapsed_ms'] += $elapsed;
                    $metrics['phase_ms'][$phase] = ($metrics['phase_ms'][$phase] ?? 0) + $elapsed;
                    $metrics['peak_memory_bytes'] = max($metrics['peak_memory_bytes'], memory_get_peak_usage(true));
                    $limit = $phase === 'validate' ? $generator->visits : $targets[$phase];
                    $nextPhase = $phase;
                    $nextLast = $end;
                    $status = 'processing';
                    $completed = null;
                    if ($end === $limit) {
                        $nextPhase = ['branches' => 'doctors', 'doctors' => 'patients', 'patients' => 'visits', 'visits' => 'validate', 'validate' => 'done'][$phase];
                        $nextLast = 0;
                        if ($nextPhase === 'done') {
                            $finalStarted = hrtime(true);
                            $metrics['verification'] = $this->verifyTotals($db, $generator);
                            $metrics['peak_memory_bytes'] = max($metrics['peak_memory_bytes'], memory_get_peak_usage(true));
                            $extra = (int) ((hrtime(true) - $finalStarted) / 1000000);
                            $metrics['active_elapsed_ms'] += $extra;
                            $metrics['phase_ms']['validate'] += $extra;
                            $status = 'done';
                            $completed = gmdate('Y-m-d H:i:s');
                            $metrics['wall_seconds'] = time() - strtotime($current->started_at.' UTC');
                            $metrics['under_30_minutes'] = $metrics['attempts'] === 1 && $metrics['wall_seconds'] < 1800;
                        }
                    }
                    $db->table('seed_runs')->where('id', $run->id)->update([
                        'phase' => $nextPhase, 'last_committed_id' => $nextLast, 'row_counts' => json_encode($counts, JSON_THROW_ON_ERROR),
                        'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'status' => $status,
                        'completed_at' => $completed, 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    return $this->state($db->table('seed_runs')->where('id', $run->id)->first(), $generator);
                }, 3);
                $progress($state);
                $batches++;
                if ($state['status'] === 'done' || ($maxBatches > 0 && $batches >= $maxBatches)) {
                    return $state;
                }
            }
        } catch (Throwable $exception) {
            if ($run !== null) {
                try {
                    $this->assertLock($db, $lock);
                    $db->table('seed_runs')->where('id', $run->id)->where('status', '!=', 'done')
                        ->update(['status' => 'failed', 'error_code' => 'seed_interrupted', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    $progress($this->state($db->table('seed_runs')->where('id', $run->id)->first(), $generator));
                } catch (Throwable) {
                    // A lost connection leaves the committed checkpoint intact.
                }
            }
            throw $exception;
        } finally {
            try {
                $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
            } catch (Throwable) {
                // Server releases the session lock when the connection closes.
            }
            $db->setReconnector(static fn (Connection $connection): Connection => DB::reconnect($connection->getName()));
        }
    }

    private function assertLock(Connection $db, string $lock): void
    {
        $result = $db->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$lock], false);
        if ((int) $result->owned !== 1) {
            throw new RuntimeException('Seed lock lost; rerun to resume.');
        }
    }

    /** @param array{patients: int, visits: int, batch: int, seed: int} $options */
    private function openRun(Connection $db, array $options): object
    {
        $hash = hash('sha256', json_encode(['version' => ClinicSeedGenerator::VERSION] + $options, JSON_THROW_ON_ERROR));
        $run = $db->table('seed_runs')->where('run_key', 'clinic-v1')->first();
        if ($run !== null) {
            if (! hash_equals($run->config_hash, $hash)) {
                throw new RuntimeException('Seed parameters changed. Resume with the original options or use a different empty database.');
            }
            return $run;
        }
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $end->modify('-5 years');
        $generator = new ClinicSeedGenerator($options['patients'], $options['visits'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $options['seed']);
        foreach (array_merge(array_keys($generator->targets()), self::SUMMARY_TABLES, ['seed_runs']) as $table) {
            if ($db->table($table)->exists()) {
                throw new RuntimeException('Seed target must be empty; found rows in '.$table.'.');
            }
        }
        $config = ['version' => ClinicSeedGenerator::VERSION, 'range_start' => $generator->rangeStart, 'range_end' => $generator->rangeEnd] + $options;
        $db->table('seed_runs')->insert([
            'run_key' => 'clinic-v1', 'config' => json_encode($config, JSON_THROW_ON_ERROR), 'config_hash' => $hash,
            'phase' => 'branches', 'last_committed_id' => 0, 'row_counts' => json_encode(array_fill_keys(array_keys($generator->targets()), 0), JSON_THROW_ON_ERROR),
            'metrics' => json_encode(['active_elapsed_ms' => 0, 'peak_memory_bytes' => 0, 'phase_ms' => [], 'attempts' => 0], JSON_THROW_ON_ERROR),
            'status' => 'processing', 'started_at' => $generator->rangeEnd, 'created_at' => $generator->rangeEnd, 'updated_at' => $generator->rangeEnd,
        ]);
        return $db->table('seed_runs')->where('run_key', 'clinic-v1')->first();
    }

    private function addSummaries(Connection $db, int $from, int $to): void
    {
        $source = ' FROM invoices i JOIN visits v ON v.id = i.visit_id
            LEFT JOIN (SELECT invoice_id, SUM(amount_minor) AS paid FROM payments WHERE invoice_id BETWEEN ? AND ? GROUP BY invoice_id) p ON p.invoice_id = i.id
            WHERE i.id BETWEEN ? AND ? AND i.status <> ?';
        $bindings = [$from, $to, $from, $to, 'void'];
        foreach (['branch', 'doctor'] as $dimension) {
            $table = 'daily_'.$dimension.'_revenue';
            $keys = $dimension === 'branch' ? 'billing_date, branch_id' : 'billing_date, doctor_id, branch_id';
            $selection = $dimension === 'branch' ? 'i.billing_date, v.branch_id' : 'i.billing_date, v.doctor_id, v.branch_id';
            $columns = ['invoice_count', 'gross_minor', 'discount_minor', 'vat_minor', 'net_minor', 'paid_minor'];
            $updates = implode(', ', array_map(fn (string $column): string => "$column = $table.$column + VALUES($column)", $columns));
            $db->statement("INSERT INTO $table ($keys, ".implode(', ', $columns).", updated_at)
                SELECT $selection, COUNT(*), SUM(i.total_minor), SUM(i.discount_minor), SUM(i.vat_minor), SUM(i.net_minor), SUM(COALESCE(p.paid, 0)), UTC_TIMESTAMP()
                $source GROUP BY $selection ON DUPLICATE KEY UPDATE $updates, updated_at = UTC_TIMESTAMP()", $bindings);
        }
        $db->statement("INSERT INTO patient_year_totals (year, patient_id, invoice_count, net_minor, updated_at)
            SELECT YEAR(i.billing_date), v.patient_id, COUNT(*), SUM(i.net_minor), UTC_TIMESTAMP()
            FROM invoices i JOIN visits v ON v.id = i.visit_id WHERE i.id BETWEEN ? AND ? AND i.status <> ?
            GROUP BY YEAR(i.billing_date), v.patient_id
            ON DUPLICATE KEY UPDATE invoice_count = patient_year_totals.invoice_count + VALUES(invoice_count),
            net_minor = patient_year_totals.net_minor + VALUES(net_minor), updated_at = UTC_TIMESTAMP()", [$from, $to, 'void']);
    }

    private function validateBatch(Connection $db, int $from, int $to, ClinicSeedGenerator $generator): void
    {
        $bad = $db->selectOne("SELECT COUNT(*) AS total FROM invoices i
            JOIN visits v ON v.id = i.visit_id JOIN patients patient ON patient.id = v.patient_id
            JOIN doctors d ON d.id = v.doctor_id
            LEFT JOIN (SELECT invoice_id, COUNT(*) AS item_count, SUM(qty * unit_price_minor) AS gross FROM invoice_items WHERE invoice_id BETWEEN ? AND ? GROUP BY invoice_id) item ON item.invoice_id = i.id
            LEFT JOIN (SELECT invoice_id, SUM(amount_minor) AS paid, MIN(paid_at) AS first_paid, MAX(paid_at) AS last_paid FROM payments WHERE invoice_id BETWEEN ? AND ? GROUP BY invoice_id) p ON p.invoice_id = i.id
            WHERE i.id BETWEEN ? AND ? AND (
                COALESCE(item.item_count, 0) <> 3 OR item.gross <> i.total_minor OR d.branch_id <> v.branch_id
                OR COALESCE(p.paid, 0) > i.net_minor OR (i.status = 'void' AND COALESCE(p.paid, 0) <> 0)
                OR (i.status = 'paid' AND COALESCE(p.paid, 0) <> i.net_minor)
                OR (i.status = 'unpaid' AND COALESCE(p.paid, 0) <> 0)
                OR (i.status = 'partially_paid' AND (COALESCE(p.paid, 0) <= 0 OR p.paid >= i.net_minor))
                OR v.visit_date < ? OR v.visit_date > ? OR patient.dob > DATE(v.visit_date)
                OR patient.created_at > v.visit_date OR i.created_at < v.visit_date
                OR p.first_paid < i.created_at OR p.last_paid > ?
            )", [$from, $to, $from, $to, $from, $to, $generator->rangeStart, $generator->rangeEnd, $generator->rangeEnd], false);
        if ((int) $bad->total !== 0) {
            throw new RuntimeException('Seed source reconciliation failed.');
        }
    }

    /** @return array<string, mixed> */
    private function verifyTotals(Connection $db, ClinicSeedGenerator $generator): array
    {
        $counts = [];
        foreach ($generator->targets() as $table => $target) {
            $counts[$table] = $db->table($table)->count();
            if ($counts[$table] !== $target) {
                throw new RuntimeException('Unexpected row count for '.$table.'.');
            }
        }
        $source = $db->table('invoices')->where('status', '!=', 'void')
            ->selectRaw('COUNT(*) AS invoice_count, SUM(total_minor) AS gross_minor, SUM(discount_minor) AS discount_minor, SUM(vat_minor) AS vat_minor, SUM(net_minor) AS net_minor')->first();
        $net = (int) $source->net_minor;
        foreach (self::SUMMARY_TABLES as $table) {
            if ((int) $db->table($table)->sum('net_minor') !== $net) {
                throw new RuntimeException('Summary reconciliation failed: '.$table);
            }
            if ((int) $db->table($table)->sum('invoice_count') !== (int) $source->invoice_count) {
                throw new RuntimeException('Summary invoice count mismatch: '.$table);
            }
        }
        $paid = (int) $db->table('payments')->sum('amount_minor');
        foreach (['daily_branch_revenue', 'daily_doctor_revenue'] as $table) {
            if ((int) $db->table($table)->sum('paid_minor') !== $paid) {
                throw new RuntimeException('Payment summary reconciliation failed.');
            }
            foreach (['gross_minor', 'discount_minor', 'vat_minor'] as $column) {
                if ((int) $db->table($table)->sum($column) !== (int) $source->$column) {
                    throw new RuntimeException('Summary amount mismatch: '.$table.'.'.$column);
                }
            }
        }
        $histories = $db->selectOne('SELECT COUNT(*) AS patients, SUM(branch_count > 1) AS cross_branch, SUM(visit_count BETWEEN 2 AND 5) AS typical, SUM(visit_count >= 50) AS long_tail FROM (SELECT patient_id, COUNT(*) AS visit_count, COUNT(DISTINCT branch_id) AS branch_count FROM visits GROUP BY patient_id) histories', [], false);
        if ((int) $histories->patients !== $generator->patients || (int) $histories->cross_branch !== intdiv($generator->patients, 5)) {
            throw new RuntimeException('Patient history distribution mismatch.');
        }
        $months = $db->table('visits')->selectRaw('YEAR(visit_date) AS year, MONTH(visit_date) AS month, COUNT(*) AS visits')
            ->groupByRaw('YEAR(visit_date), MONTH(visit_date)')->orderBy('year')->orderBy('month')->get()->all();
        $sizes = $db->select('SELECT table_name, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()', [], false);
        return ['rows' => $counts, 'cross_branch_patients' => (int) $histories->cross_branch,
            'typical_histories' => (int) $histories->typical, 'long_tail_histories' => (int) $histories->long_tail,
            'monthly_visits' => $months, 'net_minor' => $net, 'paid_minor' => $paid, 'table_sizes' => $sizes];
    }

    /** @return array<string, mixed> */
    private function state(object $run, ClinicSeedGenerator $generator): array
    {
        return ['status' => $run->status, 'phase' => $run->phase, 'last_committed_id' => (int) $run->last_committed_id,
            'row_counts' => json_decode($run->row_counts, true, flags: JSON_THROW_ON_ERROR),
            'metrics' => json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR),
            'targets' => $generator->targets(), 'range_start' => $generator->rangeStart, 'range_end' => $generator->rangeEnd];
    }
}
