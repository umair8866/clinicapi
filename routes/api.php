<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ImportController;

Route::get('/user', function (Request $request): UserResource {
    return new UserResource($request->user());
})->middleware(['auth:sanctum', 'api.token', 'active', 'throttle:60,1'])->name('api.user');

Route::middleware(['auth:sanctum', 'api.token', 'active', 'throttle:60,1'])->group(function (): void {
    Route::get('patients', [PatientController::class, 'index'])->middleware('throttle:30,1');
    Route::get('patients/{patient}/visits', [PatientController::class, 'visits']);
    Route::get('visits', [VisitController::class, 'index']);
    Route::post('visits', [VisitController::class, 'store'])->middleware('throttle:20,1');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('reports/revenue', [ReportController::class, 'revenue'])->middleware('throttle:20,1');
    Route::get('reports/top-patients', [ReportController::class, 'topPatients'])->middleware('throttle:20,1');
    Route::post('exports/visits', [ExportController::class, 'store'])->middleware('throttle:5,1');
    Route::get('exports/{export}', [ExportController::class, 'show']);
    Route::post('imports/patients', [ImportController::class, 'store'])->middleware('throttle:5,1');
    Route::get('imports/{import}', [ImportController::class, 'show']);
});
