<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\VisitQueryService;
use App\UserRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExportVisits implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 240];

    public function __construct(public int $exportId)
    {
    }
    public function handle(VisitQueryService $queries): void
    {
        $export = Export::findOrFail($this->exportId);
        if ($export->status === 'done') {
            return;
        }
        $this->authorizeExport($export);
        $export->update(['status' => 'processing']);
        while (true) {
            $export->refresh();
            $user = $this->authorizeExport($export);
            $query = $queries->query($user, $export->filters)->where('visits.id', '<=', $export->max_visit_id);
            if ($export->last_visit_id !== null) {
                $date = $export->last_visit_date->format('Y-m-d H:i:s');
                $query->where(function ($query) use ($date, $export): void {
                    $query->where('visit_date', '<', $date)->orWhere(function ($query) use ($date, $export): void {
                        $query->where('visit_date', $date)->where('visits.id', '<', $export->last_visit_id);
                    });
                });
            }
            $rows = $query->with(['doctor:id,name','branch:id,name'])->limit(1000)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $part = $export->parts + 1;
            $this->uploadPart($export, $rows, $part);
            $last = $rows->last();
            DB::transaction(function () use ($export, $last, $rows, $part): void {
                $current = Export::whereKey($export->id)->lockForUpdate()->firstOrFail();
                if ($current->parts !== $part - 1) {
                    return;
                }
                $current->update([
                    'parts' => $part,
                    'rows_processed' => $current->rows_processed + $rows->count(),
                    'last_visit_date' => $last->visit_date,
                    'last_visit_id' => $last->id,
                ]);
            });
        }
        $this->finishExport($export);
    }

    private function authorizeExport(Export $export): \App\Models\User
    {
        $user = $export->user()->first();
        if (! $user?->hasAbility('exports:write') || ! in_array($user->role, [UserRole::Admin, UserRole::ClinicAdmin], true)) {
            throw new RuntimeException('Export authorization changed.');
        }

        if ($export->branch_scope === [] && $user->role !== UserRole::Admin) {
            throw new RuntimeException('Export role changed.');
        }

        if ($user->role !== UserRole::Admin) {
            $current = $user->branches()->pluck('branches.id')->sort()->values()->all();
            $original = collect($export->branch_scope)->sort()->values()->all();
            if ($current !== $original) {
                throw new RuntimeException('Export branch scope changed.');
            }
        }

        return $user;
    }

    private function uploadPart(Export $export, $rows, int $part): void
    {
        $key = dirname($export->object_key).'/parts/'.sprintf('%08d', $part).'.csv';
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            foreach ($rows as $visit) {
                fputcsv($stream, [
                    $visit->id,
                    $visit->patient_id,
                    $visit->doctor_id,
                    $visit->branch_id,
                    $visit->visit_date->format('Y-m-d H:i:s'),
                    $visit->status,
                    $this->safe($visit->doctor->name),
                    $this->safe($visit->branch->name),
                ]);
            }
            rewind($stream);
            if (! Storage::disk()->put($key, $stream)) {
                throw new RuntimeException('S3 upload failed.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function finishExport(Export $export): void
    {
        $disk = Storage::disk();
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            fputcsv($stream, ['visit_id', 'patient_id', 'doctor_id', 'branch_id', 'visit_date_utc', 'status', 'doctor', 'branch']);
            for ($part = 1; $part <= $export->parts; $part++) {
                $key = dirname($export->object_key).'/parts/'.sprintf('%08d', $part).'.csv';
                $source = $disk->readStream($key);
                if (!$source) {
                    throw new RuntimeException('Export part missing.');
                }
                stream_copy_to_stream($source, $stream);
                fclose($source);
            }
            rewind($stream);
            if (!$disk->put($export->object_key, $stream)) {
                throw new RuntimeException('Final S3 upload failed.');
            }
        } finally {
            fclose($stream);
        }
        $export->update(['status' => 'done']);
    }

    private function safe(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }
    public function failed(?Throwable $exception): void
    {
        Export::whereKey($this->exportId)->where('status', '!=', 'done')->update([
            'status' => 'failed',
            'error_code' => 'export_failed',
        ]);
    }
}
