<?php

use App\Http\Controllers\Api\MockSalesDocumentController;
use App\Http\Controllers\Api\MockServiceDocumentController;
use App\Http\Controllers\Api\VehicleDocumentController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/v1/vehicles/{vin}/documents',
    VehicleDocumentController::class
)->name('vehicles.documents.index');

Route::get(
    '/mock/sales/vehicles/{vin}/documents',
    MockSalesDocumentController::class
)->name('mock.sales.documents.index');

Route::get(
    '/mock/service/vehicles/{vin}/documents',
    MockServiceDocumentController::class
)->name('mock.service.documents.index');
