<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeWhatsAppController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\JustificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WhatsAppChannelController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('webhooks/whatsapp')->group(function (): void {
        Route::get('/', [WhatsAppWebhookController::class, 'verify']);
        Route::post('/', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:120,1');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/tenants', [AuthController::class, 'tenants']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/platform/tenants', [TenantController::class, 'index']);
        Route::post('/platform/tenants', [TenantController::class, 'store']);
        Route::apiResource('/platform/whatsapp/channels', WhatsAppChannelController::class)
            ->parameters(['channels' => 'channel']);
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/tenant', [TenantController::class, 'showCurrent']);
        Route::patch('/tenant', [TenantController::class, 'updateCurrent']);

        Route::apiResource('branches', BranchController::class);

        Route::apiResource('employees', EmployeeController::class);
        Route::put('/employees/{employee}/branches', [EmployeeController::class, 'assignBranches']);
        Route::get('/employees/{employee}/whatsapp-links', [EmployeeWhatsAppController::class, 'index']);
        Route::delete('/employees/{employee}/whatsapp-links/{link}', [EmployeeWhatsAppController::class, 'destroy']);

        Route::apiResource('schedules', ScheduleController::class);
        Route::post('/schedules/{schedule}/assign', [ScheduleController::class, 'assignToEmployee']);

        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::get('/attendance/{attendance}', [AttendanceController::class, 'show']);
        Route::post('/attendance/{attendance}/correct', [AttendanceController::class, 'correct']);

        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
        Route::post('/incidents/{incident}/approve', [IncidentController::class, 'approve']);
        Route::post('/incidents/{incident}/reject', [IncidentController::class, 'reject']);

        Route::get('/justifications', [JustificationController::class, 'index']);
        Route::post('/justifications', [JustificationController::class, 'store']);
        Route::post('/justifications/{justification}/approve', [JustificationController::class, 'approve']);
        Route::post('/justifications/{justification}/reject', [JustificationController::class, 'reject']);

        Route::get('/reports/attendance-summary', [ReportController::class, 'summary']);
        Route::get('/reports/attendance-export', [ReportController::class, 'export']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::apiResource('whatsapp/channels', WhatsAppChannelController::class)
            ->parameters(['channels' => 'channel'])
            ->names('tenant.whatsapp.channels');
    });
});
