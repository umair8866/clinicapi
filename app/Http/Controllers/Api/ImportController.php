<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\ImportPatients;
use App\Models\Import;
use App\UserRole;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request): ImportResource
    {
        $file = $request->file('file');
        $key = 'imports/'.Str::uuid().'/source.csv';
        $source = fopen($file->getRealPath(), 'rb');
        $header = fgetcsv($source);
        $rows = 0;
        while (fgetcsv($source) !== false) {
            $rows++;
            if ($rows > 500000) {
                break;
            }
        }
        fclose($source);
        if ($header !== ['mrn','cpr','name','phone','dob','gender'] || $rows > 500000) {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'CSV must have the required header and at most 500000 rows.']);
        }
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            abort_unless(Storage::disk()->put($key, $stream), 503, 'Storage unavailable.');
        } finally {
            fclose($stream);
        }
        $import = Import::create(['user_id' => $request->user()->id,'status' => 'queued',
         'object_key' => $key,'source_bytes' => $file->getSize()]);
        ImportPatients::dispatch($import->id)->afterCommit();
        return new ImportResource($import);
    }
    public function show(Import $import): ImportResource
    {
        $user = request()->user();
        abort_unless($user->hasAbility('imports:read') && $user->role === UserRole::Admin && $user->id === $import->user_id, 404);
        return new ImportResource($import);
    }
}
