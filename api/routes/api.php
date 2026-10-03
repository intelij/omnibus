<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('/health', [V1\HealthController::class, 'show']);
    Route::get('/health/ready', [V1\HealthController::class, 'ready']);

    // ─── Auth (staff/operators) ────────────────────────────────────
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('login', [V1\AuthController::class, 'login']);
        Route::post('forgot-password', [V1\AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [V1\AuthController::class, 'resetPassword']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::get('auth/me', [V1\AuthController::class, 'me']);
    });

    // ─── Provider webhooks (signature-verified per driver) ─────────
    Route::post('webhooks/payments/{gateway}', [V1\PaymentWebhookController::class, 'handle'])
        ->name('webhooks.payments');

    // ─── Public booking surface (tenant resolved from host) ────────
    Route::prefix('public')->middleware(['public.tenant', 'throttle:public'])->group(function () {
        Route::get('tenant', [V1\Public\TenantController::class, 'show']);
        Route::get('endpoints', [V1\Public\SearchController::class, 'endpoints']);
        Route::get('trips/search', [V1\Public\SearchController::class, 'search']);
        Route::get('trips/{trip}', [V1\Public\TripController::class, 'show']);
        Route::get('trips/{trip}/seats', [V1\Public\TripController::class, 'seats']);
        Route::post('bookings', [V1\Public\BookingController::class, 'store'])
            ->middleware(['idempotent', 'throttle:bookings']);
        Route::post('bookings/{booking}/payments', [V1\Public\BookingController::class, 'pay'])
            ->middleware('idempotent');
        Route::get('bookings/{reference}', [V1\Public\BookingController::class, 'show']);
        Route::post('bookings/{reference}/cancel', [V1\Public\BookingController::class, 'cancel'])
            ->middleware('idempotent');
        Route::get('bookings/{reference}/tickets', [V1\Public\BookingController::class, 'tickets']);
        Route::post('promotions/validate', [V1\Public\PromotionController::class, 'validateCode']);
    });

    // ─── Tenant staff API (agent / admin / finance / support) ──────
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        Route::get('dashboard', [V1\DashboardController::class, 'show']);
        Route::get('onboarding', [V1\OnboardingController::class, 'show']);

        Route::apiResource('buses', V1\BusController::class);
        Route::apiResource('seat-layouts', V1\SeatLayoutController::class);
        Route::post('seat-layouts/{seat_layout}/publish', [V1\SeatLayoutController::class, 'publish']);
        Route::apiResource('routes', V1\RouteController::class);
        Route::apiResource('schedules', V1\ScheduleController::class);
        Route::post('schedules/{schedule}/generate-trips', [V1\ScheduleController::class, 'generateTrips']);

        Route::get('trips', [V1\TripController::class, 'index']);
        Route::post('trips', [V1\TripController::class, 'store']);
        Route::get('trips/{trip}', [V1\TripController::class, 'show']);
        Route::post('trips/{trip}/status', [V1\TripController::class, 'status']);
        Route::post('trips/{trip}/swap-bus', [V1\TripController::class, 'swapBus']);
        Route::get('trips/{trip}/manifest', [V1\TripController::class, 'manifest']);
        Route::get('trips/{trip}/seats', [V1\TripController::class, 'seats']);

        Route::get('bookings', [V1\Admin\BookingController::class, 'index']);
        Route::post('bookings', [V1\Admin\BookingController::class, 'store'])->middleware('idempotent');
        Route::get('bookings/{booking}', [V1\Admin\BookingController::class, 'show']);
        Route::patch('bookings/{booking}', [V1\Admin\BookingController::class, 'update']);
        Route::post('bookings/{booking}/cancel', [V1\Admin\BookingController::class, 'cancel']);
        Route::post('bookings/{booking}/resend-ticket', [V1\Admin\BookingController::class, 'resendTicket']);
        Route::post('tickets/verify', [V1\Admin\TicketController::class, 'verify']);

        Route::get('passengers', [V1\Admin\PassengerController::class, 'index']);

        Route::get('payments', [V1\Admin\PaymentController::class, 'index']);
        Route::get('payments/{payment}', [V1\Admin\PaymentController::class, 'show']);
        Route::post('payments/{payment}/confirm', [V1\Admin\PaymentController::class, 'confirm']);
        Route::post('payments/{payment}/refund', [V1\Admin\PaymentController::class, 'refund'])->middleware('idempotent');
        Route::get('payments-export', [V1\Admin\PaymentController::class, 'export']);
        Route::get('reconciliation', [V1\Admin\PaymentController::class, 'reconciliation']);

        Route::apiResource('promotions', V1\Admin\PromotionController::class);
        Route::apiResource('users', V1\Admin\UserController::class);
        Route::post('users/{user}/status', [V1\Admin\UserController::class, 'setStatus']);

        Route::apiResource('support-tickets', V1\Admin\SupportTicketController::class);
        Route::post('support-tickets/{support_ticket}/notes', [V1\Admin\SupportTicketController::class, 'addNote']);
        Route::post('support-tickets/{support_ticket}/assign', [V1\Admin\SupportTicketController::class, 'assign']);

        Route::get('reports/revenue', [V1\Admin\ReportController::class, 'revenue']);
        Route::get('reports/occupancy', [V1\Admin\ReportController::class, 'occupancy']);
        Route::get('reports/agents', [V1\Admin\ReportController::class, 'agents']);
        Route::get('reports/payments', [V1\Admin\ReportController::class, 'payments']);

        Route::get('settings', [V1\Admin\SettingsController::class, 'show']);
        Route::patch('settings/branding', [V1\Admin\SettingsController::class, 'updateBranding']);
        Route::patch('settings/rules', [V1\Admin\SettingsController::class, 'updateRules']);
        Route::patch('settings/payments', [V1\Admin\SettingsController::class, 'updatePayments']);
        Route::post('files', [V1\Admin\FileController::class, 'store']);

        Route::get('audit-logs', [V1\Admin\AuditLogController::class, 'index']);
    });

    // ─── Platform administration ────────────────────────────────────
    Route::prefix('platform')->middleware(['auth:sanctum', 'platform'])->group(function () {
        Route::get('tenants', [V1\Platform\TenantController::class, 'index']);
        Route::post('tenants', [V1\Platform\TenantController::class, 'store']);
        Route::get('tenants/{tenant}', [V1\Platform\TenantController::class, 'show']);
        Route::patch('tenants/{tenant}/status', [V1\Platform\TenantController::class, 'setStatus']);
        Route::patch('tenants/{tenant}/features', [V1\Platform\TenantController::class, 'setFeatures']);
        Route::get('reports/overview', [V1\Platform\ReportController::class, 'overview']);
        Route::get('audit-logs', [V1\Platform\AuditLogController::class, 'index']);
    });
});
