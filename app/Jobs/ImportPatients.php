<?php

namespace App\Jobs;

use App\Models\Import;
use App\UserRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportPatients implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 240];

    public function __construct(public int $importId)
    {
    }
    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);
        if ($import->status === 'done') {
            return;
        }
        if (!$import->user?->is_active || $import->user->role !== UserRole::Admin) {
            throw new RuntimeException('Import authorization changed.');
        }
        $disk = Storage::disk();
        $source = $disk->readStream($import->object_key);
        if (!$source) {
            throw new RuntimeException('Import source unavailable.');
        }
        $local = tmpfile();
        if (!$local) {
            fclose($source);
            throw new RuntimeException('Temporary import storage unavailable.');
        }
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['clinic-import'], false)->acquired !== 1) {
            fclose($source);
            fclose($local);
            throw new RuntimeException('Another patient import is active.');
        }
        try {
            stream_copy_to_stream($source, $local);
            fclose($source);
            rewind($local);
            $header = fgetcsv($local);
            if ($header !== ['mrn', 'cpr', 'name', 'phone', 'dob', 'gender']) {
                throw new RuntimeException('CSV columns must be mrn,cpr,name,phone,dob,gender.');
            }
            fseek($local, $import->next_byte_offset ?: ftell($local));
            $import->update(['status' => 'processing']);
            while (!feof($local)) {
                if (! $import->user()->where('is_active', true)->where('role', 'admin')->exists()) {
                    throw new RuntimeException('Import authorization changed.');
                }
                $valid = [];
                $errors = [];
                $seen = [];
                $read = 0;
                while ($read < 500 && ($row = fgetcsv($local)) !== false) {
                    $read++;
                    $number = $import->processed_rows + $read + 1;
                    if (count($row) !== 6 || strlen(implode('', $row)) > 1000) {
                        $errors[] = [$number, 'Invalid column count or length'];
                        continue;
                    }
                    [$mrn, $cpr, $name, $phone, $dob, $gender] = $row;
                    $birthDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
                    if (!preg_match('/^[A-Za-z0-9-]{1,32}$/', $mrn) || !preg_match('/^[A-Za-z0-9-]{1,32}$/', $cpr)
                     || !preg_match('/^.{1,150}$/u', $name) || !preg_match('/^\+?[0-9]{7,20}$/', $phone)
                     || !in_array($gender, ['male', 'female', 'other', 'unknown'], true)
                     || $birthDate === false || $birthDate->format('Y-m-d') !== $dob) {
                        $errors[] = [$number, 'Invalid patient data'];
                        continue;
                    }
                    if (isset($seen[$mrn]) || in_array($cpr, $seen, true)) {
                        $errors[] = [$number, 'Duplicate identifier in chunk'];
                        continue;
                    }
                    $seen[$mrn] = $cpr;
                    $valid[] = [
                        'row_number' => $number,
                        'mrn' => $mrn,
                        'cpr' => $cpr,
                        'name' => $name,
                        'phone' => $phone,
                        'dob' => $dob,
                        'gender' => $gender,
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ];
                }
                if ($read === 0) {
                    break;
                }
                if ($import->processed_rows + $read > 500000) {
                    throw new RuntimeException('CSV exceeds 500000 rows.');
                }
                $conflicts = DB::table('patients')->whereIn('cpr', array_column($valid, 'cpr'))->get(['mrn','cpr'])->keyBy('cpr');
                $accepted = [];
                foreach ($valid as $row) {
                    if (isset($conflicts[$row['cpr']]) && $conflicts[$row['cpr']]->mrn !== $row['mrn']) {
                        $errors[] = [$row['row_number'], 'CPR belongs to another MRN'];
                    } else {
                        unset($row['row_number']);
                        $accepted[] = $row;
                    }
                }
                $next = ftell($local);
                $chunk = intdiv($import->processed_rows, 500) + 1;
                $errorKey = dirname($import->object_key).'/errors/'.sprintf('%08d', $chunk).'.csv';
                $errorStream = fopen('php://temp', 'w+b');
                foreach ($errors as $error) {
                    fputcsv($errorStream, $error);
                }
                rewind($errorStream);
                try {
                    if (!$disk->put($errorKey, $errorStream)) {
                        throw new RuntimeException('Error chunk upload failed.');
                    }
                } finally {
                    fclose($errorStream);
                }
                DB::transaction(function () use ($import, $accepted, $errors, $read, $next): void {
                    if ($accepted !== []) {
                        try {
                            DB::table('patients')->upsert($accepted, ['mrn'], ['cpr', 'name', 'phone', 'dob', 'gender', 'updated_at']);
                        } catch (\Illuminate\Database\QueryException) {
                            throw new RuntimeException('Patient batch persistence failed.');
                        }
                    }
                    $current = Import::whereKey($import->id)->lockForUpdate()->firstOrFail();
                    if ($current->next_byte_offset !== $import->next_byte_offset) {
                        throw new RuntimeException('Import checkpoint changed.');
                    }
                    $current->update([
                        'processed_rows' => $current->processed_rows + $read,
                        'valid_rows' => $current->valid_rows + count($accepted),
                        'invalid_rows' => $current->invalid_rows + count($errors),
                        'next_byte_offset' => $next,
                    ]);
                });
                $import->refresh();
            }
            if ($import->invalid_rows > 0) {
                $report = fopen('php://temp/maxmemory:2097152', 'w+b');
                try {
                    fputcsv($report, ['row', 'error']);
                    for ($chunk = 1; $chunk <= intdiv($import->processed_rows + 499, 500); $chunk++) {
                        $key = dirname($import->object_key).'/errors/'.sprintf('%08d', $chunk).'.csv';
                        $part = $disk->readStream($key);
                        if (!$part) {
                            throw new RuntimeException('Error chunk missing.');
                        }
                        stream_copy_to_stream($part, $report);
                        fclose($part);
                    }
                    rewind($report);
                    $reportKey = dirname($import->object_key).'/errors.csv';
                    if (!$disk->put($reportKey, $report)) {
                        throw new RuntimeException('Error report upload failed.');
                    }
                } finally {
                    fclose($report);
                }
                $import->error_object_key = $reportKey;
            }
            $import->status = 'done';
            $import->save();
        } finally {
            fclose($local);
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['clinic-import'], false);
        }
    }
    public function failed(?Throwable $exception): void
    {
        Import::whereKey($this->importId)->where('status', '!=', 'done')->update([
            'status' => 'failed',
            'error_code' => 'import_failed',
        ]);
    }
}
