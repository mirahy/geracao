<?php

use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SolarInstallmentController;
use App\Http\Controllers\Api\Admin\UnitRequestController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\RateioPaymentController;
use App\Http\Controllers\Api\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::put('me/password', [AuthController::class, 'updatePassword']);
        Route::get('months', [DashboardController::class, 'months']);
        Route::get('dashboard', [DashboardController::class, 'show']);
        Route::get('units', [UnitController::class, 'index']);
        Route::post('units', [UnitController::class, 'store']);
        Route::get('units/{unit}', [UnitController::class, 'show']);
        Route::put('units/{unit}', [UnitController::class, 'update']);
        Route::delete('units/{unit}', [UnitController::class, 'destroy']);
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('generations', [GenerationController::class, 'index']);
        Route::post('generations', [GenerationController::class, 'store']);
        Route::post('generations/import', [GenerationController::class, 'import']);
        Route::get('generations/pending', [GenerationController::class, 'pending']);
        Route::post('generations/resolve', [GenerationController::class, 'resolve']);
        Route::delete('generations/{generation}', [GenerationController::class, 'destroy']);

        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents/import', [DocumentController::class, 'import']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        Route::prefix('admin')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
            Route::post('users/{user}/grant-requested-unit', [UserController::class, 'grantRequestedUnit']);

            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);

            Route::get('unit-requests', [UnitRequestController::class, 'index']);
            Route::post('unit-requests/bulk', [UnitRequestController::class, 'bulk']);
            Route::post('unit-requests/{unitRequest}/approve', [UnitRequestController::class, 'approve']);
            Route::post('unit-requests/{unitRequest}/reject', [UnitRequestController::class, 'reject']);

            Route::get('installments', [SolarInstallmentController::class, 'index']);
            Route::post('installments', [SolarInstallmentController::class, 'store']);
            Route::put('installments/{installment}', [SolarInstallmentController::class, 'update']);
            Route::delete('installments/{installment}', [SolarInstallmentController::class, 'destroy']);
        });

        Route::prefix('rateio')->group(function () {
            Route::get('summary', [RateioPaymentController::class, 'summary']);
            Route::get('payments', [RateioPaymentController::class, 'index']);
            Route::post('payments', [RateioPaymentController::class, 'store']);
            Route::delete('payments/{payment}', [RateioPaymentController::class, 'destroy']);
            Route::get('payments/{payment}/receipt', [RateioPaymentController::class, 'receipt']);
            Route::post('receipts/read', [RateioPaymentController::class, 'readReceipt']);
        });
    });
});
