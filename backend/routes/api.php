<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\HazardSourceController;
use App\Http\Controllers\RiskAreaController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:6,1')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::middleware('role:system_admin,mdrrmo,bfp_officer,alert_operator,resident')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
            Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        });

        Route::middleware('role:system_admin')->prefix('/staff')->name('staff.')->group(function (): void {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::post('/', [StaffController::class, 'store'])->name('store');
            Route::patch('/{user}/role', [StaffController::class, 'updateRole'])->name('role');
            Route::patch('/{user}/active', [StaffController::class, 'toggleActive'])->name('active');
            Route::get('/audit', [StaffController::class, 'audit'])->name('audit');
        });

        Route::middleware('role:system_admin,mdrrmo,alert_operator')->group(function (): void {
            Route::apiResource('alerts', AlertController::class)
                ->only(['index', 'store', 'show', 'update']);

            Route::patch('/alerts/{alert}/approve', [AlertController::class, 'approve'])->name('alerts.approve');
            Route::patch('/alerts/{alert}/publish', [AlertController::class, 'publish'])->name('alerts.publish');
            Route::post('/alerts/{alert}/revise', [AlertController::class, 'revise'])->name('alerts.revise');
            Route::patch('/alerts/{alert}/close', [AlertController::class, 'close'])->name('alerts.close');
        });

        Route::middleware('role:system_admin,mdrrmo,bfp_officer,alert_operator')->group(function (): void {
            Route::get('/risk-areas', [RiskAreaController::class, 'index'])->name('risk-areas.index');
            Route::get('/risk-areas/{riskArea}', [RiskAreaController::class, 'show'])->name('risk-areas.show');
            Route::get('/hazard-sources', [HazardSourceController::class, 'index'])->name('hazard-sources.index');
            Route::get('/hazard-sources/{hazardSource}', [HazardSourceController::class, 'show'])
                ->name('hazard-sources.show');
        });

        Route::middleware('role:system_admin,mdrrmo,bfp_officer')->group(function (): void {
            Route::post('/hazard-sources', [HazardSourceController::class, 'store'])->name('hazard-sources.store');
        });

        Route::middleware('role:system_admin,mdrrmo,bfp_officer,alert_operator')->group(function (): void {
            Route::patch('/hazard-sources/{hazardSource}/review', [HazardSourceController::class, 'review'])
                ->name('hazard-sources.review');
        });

        Route::middleware('role:resident')->group(function (): void {
            Route::get('/my/coverage', [RiskAreaController::class, 'myCoverage'])->name('my.coverage');
            Route::get('/my/alerts', [AlertController::class, 'myAlerts'])->name('my-alerts.index');
            Route::get('/my/alerts/status', [AlertController::class, 'residentStatus'])->name('my-alerts.status');
            Route::get('/my/alerts/{alert}', [AlertController::class, 'viewForResident'])->name('my-alerts.show');
        });

        Route::middleware('role:system_admin,mdrrmo')->group(function (): void {
            Route::post('/risk-areas', [RiskAreaController::class, 'store'])->name('risk-areas.store');
            Route::put('/risk-areas/{riskArea}', [RiskAreaController::class, 'update'])->name('risk-areas.update');
            Route::patch('/risk-areas/{riskArea}', [RiskAreaController::class, 'update'])->name('risk-areas.patch');
            Route::patch('/risk-areas/{riskArea}/toggle', [RiskAreaController::class, 'toggle'])
                ->name('risk-areas.toggle');
        });
    });
});
