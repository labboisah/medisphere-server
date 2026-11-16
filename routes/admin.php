<?php
use App\Http\Controllers\Admin\PaymentController;
use App\Livewire\Admin\SyncDashboard;
use App\Livewire\Admin\PaymentManagement;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/installation', \App\Livewire\Admin\InstallationSetup::class)
        ->middleware('installation.admin')->name('installation');
    Route::get('/insight-sharing', \App\Livewire\Admin\InsightSharing::class)
        ->middleware('installation.admin')->name('insight-sharing');
    Route::post('/installation/operational-sync-enrollment', \App\Http\Controllers\OperationalSyncEnrollmentController::class)
        ->middleware(['installation.admin', 'throttle:5,1'])->name('installation.operational-sync.enroll');

    Route::get('/sync', SyncDashboard::class)->middleware('role:administrator')->name('sync.index');
    Route::get('/payments', PaymentManagement::class)->middleware('role:administrator,medical_director')->name('payments.index');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('role:administrator,medical_director')->name('payments.receipt');
    
});
