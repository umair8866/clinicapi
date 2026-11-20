<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPatientRequest;
use App\Http\Requests\IndexVisitRequest;
use App\Http\Resources\PatientResource;
use App\Http\Resources\VisitResource;
use App\Models\Patient;
use App\Services\VisitQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PatientController extends Controller
{
    public function index(SearchPatientRequest $request): AnonymousResourceCollection
    {
        $search = trim($request->validated('search'));
        $query = Patient::query();
        $query->where(function ($query) use ($search): void {
            $query->where('mrn', $search)->orWhere('cpr', $search)->orWhere('phone', $search)
                ->orWhere('name', 'like', addcslashes($search, '%_\\').'%');
        });
        return PatientResource::collection($query->orderBy('id')->limit(50)->get());
    }
    public function visits(IndexVisitRequest $request, Patient $patient, VisitQueryService $service): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Patient::class);
        $visits = $service->query($request->user(), $request->validated())->where('patient_id', $patient->id)
            ->with(['doctor:id,name', 'branch:id,name', 'invoice:id,visit_id,net_minor'])
            ->cursorPaginate($request->integer('per_page', 50));
        return VisitResource::collection($visits);
    }
}
