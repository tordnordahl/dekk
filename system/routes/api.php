<?php

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\CheckoutPaymentController;
use Illuminate\Support\Facades\Route;

// Separate read-only platform API. Existing tenant APIs remain unchanged.
Route::get('/v1/portfolio/overview', \App\Http\Controllers\Api\V1\PortfolioController::class)
    ->middleware(['throttle:30,1', \App\Http\Middleware\PortfolioAccess::class])
    ->name('api.portfolio.overview');

Route::prefix('v1')->group(function () {
    Route::get('/openapi.yaml', fn()=>response()->file(public_path('openapi.yaml'),['Content-Type'=>'application/yaml']))->middleware('throttle:30,1');
    Route::post('/tokens', [ApiController::class, 'token'])->middleware('throttle:5,1');
    Route::middleware(['api.token:read', 'subscribed', 'throttle:120,1'])->group(function () {
        Route::get('/me', [ApiController::class, 'me']);
        Route::delete('/token/current', [ApiController::class, 'revokeToken']);
        Route::get('/customers', [ApiController::class, 'customers']);
        Route::get('/vehicles', [ApiController::class, 'vehicles']);
        Route::get('/tire-sets', [ApiController::class, 'tireSets']);
        Route::get('/warehouse-map', [ApiController::class, 'warehouseMap']);
        Route::get('/bookings', [ApiController::class, 'bookings']);
        Route::get('/workday', [ApiController::class, 'workday']);
        Route::get('/tire-sets/scan/{code}', [ApiController::class, 'scan']);
        Route::get('/work-orders', [ApiController::class, 'workOrders']);
        Route::get('/work-orders/{workOrder}', [ApiController::class, 'workOrder']);
    });
    Route::middleware(['api.token:workshop.write', 'subscribed', 'throttle:60,1'])->group(function () {
        Route::patch('/tire-sets/{tireSet}/status', [ApiController::class, 'moveTireSet']);
        Route::post('/tire-sets/{tireSet}/inspections', [ApiController::class, 'inspectTireSet']);
        Route::patch('/work-orders/{workOrder}/status', [ApiController::class, 'updateWorkOrder']);
        Route::patch('/work-orders/{workOrder}/tasks/{task}', [ApiController::class, 'updateWorkOrderTask']);
        Route::get('/checkout-payments/pending',[CheckoutPaymentController::class,'pending']);
        Route::post('/checkout-payments/{payment:public_id}/processing',[CheckoutPaymentController::class,'processing']);
        Route::post('/checkout-payments/{payment:public_id}/complete',[CheckoutPaymentController::class,'complete']);
    });
});
