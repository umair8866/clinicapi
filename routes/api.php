<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;

Route::get('/user', function (Request $request): UserResource {
    return new UserResource($request->user());
})->middleware(['auth:sanctum', 'api.token', 'active', 'throttle:60,1'])->name('api.user');
