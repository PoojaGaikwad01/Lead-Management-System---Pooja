<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\GoogleDemoController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\UserController;
use App\Services\Simulation\LeadSimulator;
use Illuminate\Support\Facades\Route;

// No public registration. Password resets are done by an admin.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', fn () => redirect()->route('inbox'));
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::get('inbox/updates', [InboxController::class, 'updates'])->name('inbox.updates'); // live new-lead polling
    Route::get('inbox/{lead}', [InboxController::class, 'show'])->whereNumber('lead')->name('inbox.show');
    Route::get('attachments/{message}', [AttachmentController::class, 'show'])->name('attachments.show');

    // Reply actions: LeadPolicy::reply (admin or the lead's branch manager).
    Route::post('leads/{lead}/reply', [LeadController::class, 'reply'])->middleware('throttle:30,1')->name('leads.reply');
    Route::post('leads/{lead}/mark-replied', [LeadController::class, 'markReplied'])->name('leads.mark-replied');
    Route::post('leads/{lead}/close', [LeadController::class, 'close'])->name('leads.close');
    Route::get('reports', [ReportController::class, 'index'])->name('reports');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // FCM device registration (mobile app / web push)
    Route::post('device-tokens', [DeviceTokenController::class, 'store'])->name('device-tokens.store');
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy'])->name('device-tokens.destroy');

    // In-app notification bell
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.count');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{id}', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');

    Route::middleware('role:admin')->group(function () {
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::post('leads/bulk-assign', [LeadController::class, 'bulkAssign'])->name('leads.bulk-assign');
        Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
        Route::post('leads/{lead}/remind', [LeadController::class, 'remind'])->name('leads.remind');

        // Google Ads demo tools (404 unless leads.google.demo_tools)
        Route::post('google/sample-lead', [GoogleDemoController::class, 'sampleLead'])->name('google.sample');
        Route::delete('google/sample-leads', [GoogleDemoController::class, 'clearSamples'])->name('google.samples.clear');

        // Simulation mode: one fake lead per click through the real webhook pipeline (404 unless APP_MODE=mock)
        Route::post('simulate/{type}', [SimulationController::class, 'store'])
            ->whereIn('type', array_keys(LeadSimulator::TYPES))->middleware('throttle:30,1')->name('simulate.store');
        Route::delete('simulate', [SimulationController::class, 'clear'])->name('simulate.clear');

        Route::resource('branches', BranchController::class)->except('show');
        Route::post('branches/{branch}/managers', [BranchController::class, 'assignManager'])->name('branches.managers.store');

        Route::resource('users', UserController::class)->except('show');
        Route::put('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::patch('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    });
});
