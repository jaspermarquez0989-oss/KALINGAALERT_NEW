<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HazardSourceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RiskAreaController;
use App\Http\Controllers\StaffController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$frontendPage = static fn (Request $request): RedirectResponse => redirect()->away(
    rtrim((string) config('services.frontend.url'), '/').$request->getRequestUri(),
);

Route::get('/', $frontendPage)->name('home');

Route::middleware('guest')->group(function () use ($frontendPage): void {
    Route::get('/login', $frontendPage)->name('login');
    Route::get('/register', $frontendPage)->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.store');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1')->name('register.store');
});

Route::middleware('auth')->group(function () use ($frontendPage): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('role:system_admin,mdrrmo,bfp_officer,alert_operator,resident')->group(function () use ($frontendPage): void {
        Route::get('/dashboard', $frontendPage)->name('dashboard');
        Route::get('/profile', $frontendPage)->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    Route::middleware('role:system_admin,mdrrmo')->group(function () use ($frontendPage): void {
        Route::get('/risk-areas/create', $frontendPage)->name('risk-areas.create');
        Route::post('/risk-areas', [RiskAreaController::class, 'store'])->name('risk-areas.store');
        Route::get('/risk-areas/{riskArea}/edit', $frontendPage)->name('risk-areas.edit');
        Route::put('/risk-areas/{riskArea}', [RiskAreaController::class, 'update'])->name('risk-areas.update');
        Route::patch('/risk-areas/{riskArea}/toggle', [RiskAreaController::class, 'toggle'])->name('risk-areas.toggle');
    });

    Route::middleware('role:system_admin,mdrrmo,bfp_officer,alert_operator')->group(function () use ($frontendPage): void {
        Route::get('/risk-areas', $frontendPage)->name('risk-areas.index');
        Route::get('/hazard-sources', $frontendPage)->name('hazard-sources.index');
        Route::get('/hazard-sources/{hazardSource}/review', $frontendPage)->name('hazard-sources.review.show');
        Route::patch('/hazard-sources/{hazardSource}/review', [HazardSourceController::class, 'review'])->name('hazard-sources.review');
    });

    Route::middleware('role:system_admin,mdrrmo,bfp_officer')->group(function () use ($frontendPage): void {
        Route::get('/hazard-sources/create', $frontendPage)->name('hazard-sources.create');
        Route::post('/hazard-sources', [HazardSourceController::class, 'store'])->name('hazard-sources.store');
    });

    Route::middleware('role:system_admin,mdrrmo,alert_operator')->group(function () use ($frontendPage): void {
        Route::get('/alerts', $frontendPage)->name('alerts.index');
        Route::get('/alerts/create', $frontendPage)->name('alerts.create');
        Route::get('/alerts/{alert}', $frontendPage)->name('alerts.show');
        Route::get('/alerts/{alert}/edit', $frontendPage)->name('alerts.edit');
        Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');
        Route::put('/alerts/{alert}', [AlertController::class, 'update'])->name('alerts.update');
        Route::patch('/alerts/{alert}/approve', [AlertController::class, 'approve'])->name('alerts.approve');
        Route::patch('/alerts/{alert}/publish', [AlertController::class, 'publish'])->name('alerts.publish');
        Route::post('/alerts/{alert}/revise', [AlertController::class, 'revise'])->name('alerts.revise');
        Route::patch('/alerts/{alert}/close', [AlertController::class, 'close'])->name('alerts.close');
    });

    Route::middleware('role:resident')->group(function () use ($frontendPage): void {
        Route::get('/my-alerts', $frontendPage)->name('resident.alerts.index');
        Route::get('/my-alerts/status', [AlertController::class, 'residentStatus'])->name('resident.alerts.status');
        Route::get('/my-alerts/{alert}', [AlertController::class, 'viewForResident'])->name('resident.alerts.show');
    });

    Route::middleware('role:system_admin')->prefix('/staff')->name('staff.')->group(function () use ($frontendPage): void {
        Route::get('/', $frontendPage)->name('index');
        Route::get('/create', $frontendPage)->name('create');
        Route::get('/audit', $frontendPage)->name('audit');
        Route::post('/', [StaffController::class, 'store'])->name('store');
        Route::patch('/{user}/role', [StaffController::class, 'updateRole'])->name('role');
        Route::patch('/{user}/active', [StaffController::class, 'toggleActive'])->name('active');
    });
});

Route::fallback(fn () => abort(404));
