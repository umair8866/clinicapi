<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/samples/patients-import/{format}', function (string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse {
    return response()->download(public_path('samples/patients-import.'.$format));
})->whereIn('format', ['csv', 'xlsx'])->name('samples.patients-import');
