<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevenueReportRequest;
use App\Http\Requests\TopPatientsRequest;
use App\Http\Resources\RevenueResource;
use App\Http\Resources\TopPatientResource;
use App\Services\RevenueReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function revenue(RevenueReportRequest $request, RevenueReportService $service): AnonymousResourceCollection
    {
        return RevenueResource::collection($service->revenue($request->user(), $request->validated()));
    }
    public function topPatients(TopPatientsRequest $request, RevenueReportService $service): AnonymousResourceCollection
    {
        return TopPatientResource::collection($service->topPatients((int) $request->validated('year')));
    }
}
