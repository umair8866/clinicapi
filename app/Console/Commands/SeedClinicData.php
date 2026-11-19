<?php

namespace App\Console\Commands;

use App\Services\ClinicSeedService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SeedClinicData extends Command
{
    protected $signature = 'data:seed
        {--patients=1000000 : Number of synthetic patients}
        {--visits=5000000 : Number of synthetic visits, at least twice the patients}
        {--batch=1000 : Visits or parent rows per transaction, maximum 1000}
        {--seed=1729 : Deterministic generator seed}
        {--max-batches=0 : Stop after this many committed batches; zero runs to completion}';

    protected $description = 'Generate resumable synthetic clinic data in an empty, dedicated MySQL/MariaDB database';

    public function handle(ClinicSeedService $service): int
    {
        try {
            $options = [];
            foreach (['patients', 'visits', 'batch', 'seed', 'max-batches'] as $option) {
                $value = (string) $this->option($option);
                if (! ctype_digit($value) || strlen($value) > 10 || (int) $value > 1000000000) {
                    throw new RuntimeException('Options must be nonnegative integers no larger than 1000000000.');
                }
                $options[$option] = (int) $value;
            }
            $maxBatches = $options['max-batches'];
            unset($options['max-batches']);
            $result = $service->run($options, $maxBatches, function (array $state): void {
                $this->showProgress($state);
            });
            if ($result['status'] !== 'done') {
                $this->warn('Stopped at a committed checkpoint. Rerun with identical options to resume.');
                return self::SUCCESS;
            }
            $this->info('Seed complete and reconciled: '.$result['range_start'].' through '.$result['range_end'].' UTC');
            $seconds = intdiv($result['metrics']['active_elapsed_ms'], 1000);
            $this->line(sprintf(
                'Active duration %02d:%02d:%02d; wall duration %ds; peak memory %.1f MiB.',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
                $seconds % 60,
                $result['metrics']['wall_seconds'],
                $result['metrics']['peak_memory_bytes'] / 1048576
            ));
            $this->table(['Table', 'Rows'], array_map(
                fn (string $table, int $count): array => [$table, number_format($count)],
                array_keys($result['row_counts']),
                array_values($result['row_counts']),
            ));
            $this->line(json_encode($result['metrics'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception::class === RuntimeException::class || $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : 'Seed failed ('.$exception::class.'). Committed batches remain resumable; inspect schema and database connectivity.');
            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $state */
    private function showProgress(array $state): void
    {
        $done = array_sum($state['row_counts']);
        $total = array_sum($state['targets']);
        $seconds = max(0.001, $state['metrics']['active_elapsed_ms'] / 1000);
        $rate = $done / $seconds;
        $remaining = max(0, $total - $done);
        $phaseTarget = $state['phase'] === 'validate' ? $state['targets']['visits'] : ($state['targets'][$state['phase']] ?? 0);
        $this->line(sprintf(
            '%s: checkpoint %d/%d | %s/%s rows (%.2f%%) | %.1fs active | %.0f rows/s | insert ETA %.1fs',
            $state['phase'],
            $state['last_committed_id'],
            $phaseTarget,
            number_format($done),
            number_format($total),
            100 * $done / $total,
            $seconds,
            $rate,
            $rate > 0 ? $remaining / $rate : 0,
        ));
    }
}
