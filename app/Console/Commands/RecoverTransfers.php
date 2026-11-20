<?php

namespace App\Console\Commands;

use App\Jobs\ExportVisits;
use App\Jobs\ImportPatients;
use App\Models\Export;
use App\Models\Import;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transfers:recover')]
#[Description('Redispatch queued or stalled clinic transfers')]
class RecoverTransfers extends Command
{
    public function handle(): int
    {
        Export::query()->where('status', 'queued')->orWhere(function ($query): void {
            $query->where('status', 'processing')->where('updated_at', '<', now()->subHours(2));
        })->limit(100)->pluck('id')->each(fn (int $id) => ExportVisits::dispatch($id));
        Import::query()->where('status', 'queued')->orWhere(function ($query): void {
            $query->where('status', 'processing')->where('updated_at', '<', now()->subHours(2));
        })->limit(100)->pluck('id')->each(fn (int $id) => ImportPatients::dispatch($id));
        return self::SUCCESS;
    }
}
