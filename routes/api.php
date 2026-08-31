<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WalkInController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\MerchItemController;
use App\Http\Controllers\Api\MerchSaleController;
use App\Http\Controllers\Api\EquipmentItemController;
use App\Http\Controllers\Api\DeletionRequestController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\PaymentController as BillingController;

Route::get('/settings/{accountId}', [SettingsController::class, 'show']);
Route::put('/settings/{accountId}', [SettingsController::class, 'update']);
Route::post('/change-password', [SettingsController::class, 'changePassword']);


Route::get('/dashboard/todays-walkins', [AiController::class, 'todaysWalkIns']);
Route::get('/dashboard/todays-member-attendance', [AiController::class, 'todaysMemberAttendance']);

Route::get('/deletion-requests', [DeletionRequestController::class, 'index']);
Route::post('/deletion-requests', [DeletionRequestController::class, 'store']);
Route::post('/deletion-requests/{id}/approve', [DeletionRequestController::class, 'approve']);
Route::post('/deletion-requests/{id}/reject', [DeletionRequestController::class, 'reject']);

Route::get('/equipment-items', [EquipmentItemController::class, 'index']);
Route::post('/equipment-items', [EquipmentItemController::class, 'store']);
Route::put('/equipment-items/{id}', [EquipmentItemController::class, 'update']);
Route::post('/equipment-items/{id}/maintain', [EquipmentItemController::class, 'maintain']);
Route::delete('/equipment-items/{id}', [EquipmentItemController::class, 'destroy']);

Route::get('/dashboard/summary', [AiController::class, 'dashboardSummary']);
Route::get('/dashboard/stats', [AiController::class, 'dashboardStats']);
Route::post('/ai/chat', [AiController::class, 'chat']);

// New: churn risk + AI churn forecast (prototype)
Route::get('/dashboard/churn-risk', [AiController::class, 'churnRiskList']);
Route::get('/dashboard/churn-forecast', [AiController::class, 'churnForecast']);
Route::get('/dashboard/expiring-memberships', [AiController::class, 'expiringMemberships']);

// New: real revenue + attendance analytics for the dashboard charts
// period query param: weekly | monthly | yearly (default monthly)
Route::get('/dashboard/revenue-analytics', [AiController::class, 'revenueAnalytics']);
Route::get('/dashboard/attendance-analytics', [AiController::class, 'attendanceAnalytics']);
Route::get('/dashboard/notifications', [AiController::class, 'recentNotifications']);


Route::get('/walk-ins', [WalkInController::class, 'index']);
Route::post('/walk-ins', [WalkInController::class, 'store']);
Route::put('/walk-ins/{id}', [WalkInController::class, 'update']);
Route::delete('/walk-ins/{id}', [WalkInController::class, 'destroy']);

Route::get('/approval-requests', [ApprovalController::class, 'index']);
Route::post('/approval-requests', [ApprovalController::class, 'store']);
Route::post('/approval-requests/{id}/approve', [ApprovalController::class, 'approve']);
Route::post('/approval-requests/{id}/reject', [ApprovalController::class, 'reject']);

Route::get('/payments', [PaymentController::class, 'index']);

// --- PayMongo billing (GCash / Maya) ---
// The `api` middleware group has NO CSRF and NO auth:sanctum by default,
// so the externally-called webhook needs no extra exclusion. `store` is
// explicitly auth-gated because it derives the customer from the token.
Route::middleware('auth:sanctum')->post('/payments', [BillingController::class, 'store']);
Route::post('/payments/webhook', [BillingController::class, 'webhook']);
Route::get('/payments/{paymentId}/status', [BillingController::class, 'status']);
Route::get('/customers/{customerId}/billing-history', [BillingController::class, 'history']);
Route::post('/sync-membership', [SyncController::class, 'syncMembership']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/verify-reset-code', [AuthController::class, 'verifyResetCode']);
Route::post('/send-earnings-email', [PaymentController::class, 'sendEarningsEmail']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/customers', [CustomerController::class, 'index']);
Route::get('/customers/{qrCode}', [CustomerController::class, 'findByQr']);
Route::post('/customers', [CustomerController::class, 'store']);
Route::post('/verify-qr', [CustomerController::class, 'verifyQr']);
Route::get('/live-members', [CustomerController::class, 'liveMembers']);

Route::get('/attendance', [AttendanceController::class, 'index']);
Route::post('/attendance', [AttendanceController::class, 'store']);

Route::get('/ai/dashboard-summary', [AiController::class, 'dashboardSummary']);
Route::get('/dashboard-stats', [AiController::class, 'dashboardStats']);
Route::post('/ai/chat', [AiController::class, 'chat']);

Route::get('/merch-items', [MerchItemController::class, 'index']);
Route::post('/merch-items', [MerchItemController::class, 'store']);
Route::put('/merch-items/{id}', [MerchItemController::class, 'update']);
Route::post('/merch-items/{id}/restock', [MerchItemController::class, 'restock']);
Route::delete('/merch-items/{id}', [MerchItemController::class, 'destroy']);

Route::get('/merch-sales/revenue-analytics', [MerchSaleController::class, 'revenueAnalytics']);

Route::get('/merch-sales', [MerchSaleController::class, 'index']);
Route::post('/merch-sales', [MerchSaleController::class, 'store']);
Route::post('/merch-sales/{id}/confirm', [MerchSaleController::class, 'confirm']);
Route::post('/merch-sales/{id}/void', [MerchSaleController::class, 'void']);
Route::get('/merch-sales/stats', [MerchSaleController::class, 'stats']);

