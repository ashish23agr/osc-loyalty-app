<?php

use App\Http\Controllers\Api\Admin\RedemptionController;
use App\Http\Controllers\Api\Admin\AdjustmentController;
use App\Http\Controllers\Api\Admin\AuditController;
use App\Http\Controllers\Api\Admin\LedgerController;
use App\Http\Controllers\Api\Admin\MeController;
use App\Http\Controllers\Api\Admin\MemberController;
use App\Http\Controllers\Api\Admin\OverviewController;
use App\Http\Controllers\Api\Admin\RulesController;
use App\Http\Controllers\Api\Admin\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console API — scheme A
|--------------------------------------------------------------------------
|
| Prefixed /api/admin by bootstrap/app.php, and deliberately outside the web
| middleware group: these are called by the embedded React app with an App
| Bridge session token in the Authorization header, so there is no cookie
| session to protect and CSRF does not apply.
|
| Every route carries a staff.role floor. That floor is the authorisation, and
| it is the only authorisation — the RoleGate in the console hides what a role
| may not do, which is a convenience for the person using it and never a
| control. The floors below are the Role column of plan section 5.3, and
| AdminApiRoutingTest asserts they still match it.
|
*/

// Viewer — read the programme.
Route::middleware('staff.role:viewer')->group(function (): void {
    Route::get('/me', MeController::class);
    Route::get('/overview', OverviewController::class);

    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show'])->whereNumber('id');
    Route::get('/members/{id}/ledger', [MemberController::class, 'ledger'])->whereNumber('id');

    // The Loyalty screen. Programme-wide, so it is not under /members.
    Route::get('/ledger', [LedgerController::class, 'index']);

    Route::get('/rules', [RulesController::class, 'show']);

    // Read-only: what could be offered. The POS tile calls this before it shows
    // an amount, so nothing on screen is an offer the server has not agreed to.
    Route::post('/redemptions/quote', [RedemptionController::class, 'quote']);
    Route::get('/rules/versions/{version}', [RulesController::class, 'version'])->whereNumber('version');
});

// Agent — enrol, correct, and adjust within a limit.
Route::middleware('staff.role:agent')->group(function (): void {
    Route::post('/members', [MemberController::class, 'store']);
    Route::patch('/members/{id}', [MemberController::class, 'update'])->whereNumber('id');
    Route::post('/members/{id}/adjustments', [AdjustmentController::class, 'store'])->whereNumber('id');

    // Holding and releasing a quote changes what a member can spend, so it sits
    // at the same floor as an adjustment. C9 asks whether a till assistant on a
    // lower role should be able to act at all; until it is answered the Sprint 1
    // floors govern, and a POS action is attributed rather than exempted.
    Route::post('/redemptions', [RedemptionController::class, 'store']);
    Route::delete('/redemptions/{reference}', [RedemptionController::class, 'destroy']);
});

// Manager — maintenance, and the log that names people.
Route::middleware('staff.role:manager')->group(function (): void {
    Route::post('/members/{id}/rebuild-cache', [MemberController::class, 'rebuildCache'])->whereNumber('id');
    Route::get('/audit', [AuditController::class, 'index']);
});

// Administrator — the rules, and who may do what.
Route::middleware('staff.role:administrator')->group(function (): void {
    Route::post('/rules', [RulesController::class, 'store']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::put('/staff/{staffId}', [StaffController::class, 'update'])->whereNumber('staffId');
});
