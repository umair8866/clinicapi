<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExportRequest;
use App\Http\Resources\ExportResource;
use App\Jobs\ExportVisits;
use App\Models\Export;
use App\Services\VisitQueryService;
use App\UserRole;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function store(StoreExportRequest $request, VisitQueryService $queries): ExportResource
    {
        $user = $request->user();
        $filters = $request->validated();
        $max = (clone $queries->query($user, $filters))->max('visits.id') ?? 0;
        $scope = $user->role === UserRole::Admin ? [] : $user->branches()->pluck('branches.id')->all();
        $export = Export::create(['user_id' => $user->id,'filters' => $filters,'branch_scope' => $scope,
         'status' => 'queued','max_visit_id' => $max,'object_key' => 'exports/'.Str::uuid().'/visits.csv']);
        ExportVisits::dispatch($export->id)->afterCommit();
        return new ExportResource($export);
    }
    public function show(Export $export): ExportResource
    {
        $user = request()->user();
        abort_unless($user->hasAbility('exports:read') && in_array($user->role, [UserRole::Admin,UserRole::ClinicAdmin], true) && ($user->id === $export->user_id || $user->role === UserRole::Admin), 404);
        abort_if($export->branch_scope === [] && $user->role !== UserRole::Admin, 404);
        if ($user->role !== UserRole::Admin) {
            abort_unless($user->branches()->pluck('branches.id')->sort()->values()->all() === collect($export->branch_scope)->sort()->values()->all(), 404);
        }
        return new ExportResource($export);
    }
}
