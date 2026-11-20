<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexVisitRequest;
use App\Http\Requests\StoreVisitRequest;
use App\Http\Resources\VisitResource;
use App\Services\VisitQueryService;
use App\Services\VisitWriterService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VisitController extends Controller
{
    public function index(IndexVisitRequest $request, VisitQueryService $service): AnonymousResourceCollection
    {
        return VisitResource::collection($service->query($request->user(), $request->validated())
            ->with(['doctor:id,name', 'branch:id,name', 'invoice:id,visit_id,net_minor'])
            ->cursorPaginate($request->integer('per_page', 50)));
    }
    public function store(StoreVisitRequest $request, VisitWriterService $service): \Illuminate\Http\JsonResponse
    {
        return $service->create($request->user(), $request->validated(), (string) $request->header('Idempotency-Key'));
    }
}
