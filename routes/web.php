<?php

use App\Http\Controllers\Owner\AvailabilityController;
use App\Http\Controllers\Owner\BookingController;
use App\Http\Controllers\Owner\DepositController;
use App\Http\Controllers\Owner\ExpenseController;
use App\Http\Controllers\Owner\InvoiceController;
use App\Http\Controllers\Owner\MaintenanceController;
use App\Http\Controllers\Owner\OwnerDashboardController;
use App\Http\Controllers\Owner\PaymentController;
use App\Http\Controllers\Owner\PropertyController;
use App\Http\Controllers\Owner\ReportsController;
use App\Http\Controllers\Owner\ResidentController;
use App\Http\Controllers\Owner\RoomController;
use App\Http\Controllers\Owner\RoomPriceController;
use App\Http\Controllers\Owner\RoomTypeController;
use App\Http\Controllers\Owner\TenancyController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('dashboard', function (Request $request) {
    return $request->user()->role === 'super_admin'
        ? to_route('platform.dashboard')
        : to_route('owner.dashboard');
})->middleware(['auth', 'verified', 'active', 'role:owner,super_admin'])->name('dashboard');

Route::prefix('owner')->name('owner.')->middleware(['auth', 'verified', 'active', 'role:owner'])->group(function () {
    Route::get('/', OwnerDashboardController::class)->name('dashboard');

    Route::get('residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('residents/create', [ResidentController::class, 'create'])->name('residents.create');
    Route::post('residents', [ResidentController::class, 'store'])->name('residents.store');
    Route::get('residents/{resident}/edit', [ResidentController::class, 'edit'])->name('residents.edit');
    Route::get('residents/{resident}', [ResidentController::class, 'show'])->name('residents.show');
    Route::put('residents/{resident}', [ResidentController::class, 'update'])->name('residents.update');
    Route::post('residents/{resident}/archive', [ResidentController::class, 'archive'])->name('residents.archive');

    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/extend-grace-period', [BookingController::class, 'extendGracePeriod'])->name('bookings.extend-grace-period');
    Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn'])->name('bookings.check-in');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

    Route::get('availability', [AvailabilityController::class, 'index'])->name('availability.index');

    Route::get('tenancies', [TenancyController::class, 'index'])->name('tenancies.index');
    Route::get('direct-check-ins/create', [TenancyController::class, 'createDirect'])->name('direct-check-ins.create');
    Route::post('direct-check-ins', [TenancyController::class, 'storeDirect'])->name('direct-check-ins.store');
    Route::get('tenancies/{tenancy}', [TenancyController::class, 'show'])->name('tenancies.show');
    Route::post('tenancies/{tenancy}/check-in', [TenancyController::class, 'checkIn'])->name('tenancies.check-in');
    Route::post('tenancies/{tenancy}/extensions', [TenancyController::class, 'extend'])->name('tenancies.extensions.store');
    Route::post('tenancies/{tenancy}/check-out', [TenancyController::class, 'checkOut'])->name('tenancies.check-out');
    Route::post('tenancies/{tenancy}/cancel', [TenancyController::class, 'cancel'])->name('tenancies.cancel');

    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::get('maintenance/{work}', [MaintenanceController::class, 'show'])->name('maintenance.show');
    Route::post('maintenance/{work}/schedule', [MaintenanceController::class, 'schedule'])->name('maintenance.schedule');
    Route::post('maintenance/{work}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');
    Route::post('maintenance/{work}/cancel', [MaintenanceController::class, 'cancel'])->name('maintenance.cancel');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/adjustments', [InvoiceController::class, 'adjust'])->name('invoices.adjustments.store');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('payments/reconcile-external-overpayment', [PaymentController::class, 'reconcileExternalOverpayment'])->name('payments.external-overpayment');
    Route::post('payments/{payment}/refunds', [PaymentController::class, 'refund'])->name('payments.refunds.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

    Route::get('deposits', [DepositController::class, 'index'])->name('deposits.index');
    Route::get('deposits/{deposit}/settlements/create', [DepositController::class, 'createSettlement'])->name('deposits.settlements.create');
    Route::post('deposits/{deposit}/settlements', [DepositController::class, 'storeSettlement'])->name('deposits.settlements.store');
    Route::put('deposits/{deposit}/requirement', [DepositController::class, 'updateRequirement'])->name('deposits.requirement.update');
    Route::post('deposits/{deposit}/refunds', [DepositController::class, 'refund'])->name('deposits.refunds.store');
    Route::get('deposits/{deposit}', [DepositController::class, 'show'])->name('deposits.show');
    Route::get('deposit-settlements/{settlement}', [DepositController::class, 'showSettlement'])->name('deposit-settlements.show');
    Route::post('deposit-settlements/{settlement}/record-whatsapp-notification', [DepositController::class, 'recordWhatsApp'])->name('deposit-settlements.whatsapp-notify');
    Route::post('deposit-settlements/{settlement}/approve', [DepositController::class, 'approveSettlement'])->name('deposit-settlements.approve');
    Route::post('deposit-settlements/{settlement}/cancel', [DepositController::class, 'cancelSettlement'])->name('deposit-settlements.cancel');
    Route::post('deposit-settlements/{settlement}/claims/{line}/finalize', [DepositController::class, 'finalizeClaim'])->name('deposit-settlements.claims.finalize');
    Route::get('deposit-evidence/{evidence}', [DepositController::class, 'downloadEvidence'])->name('deposit-evidence.download');
    Route::post('temporary-liabilities/{liability}/refunds', [DepositController::class, 'refundTemporaryLiability'])->name('temporary-liabilities.refunds.store');

    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::post('expenses/{expense}/payments', [ExpenseController::class, 'recordPayment'])->name('expenses.payments.store');

    Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}/exports/{format}', [ReportsController::class, 'export'])->name('reports.export');
    Route::get('reports/{report}', [ReportsController::class, 'show'])->name('reports.show');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::get('properties/create', [PropertyController::class, 'create'])->name('properties.create');
    Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::get('properties/{property}/edit', [PropertyController::class, 'edit'])->name('properties.edit');
    Route::get('properties/{property}', [PropertyController::class, 'show'])->name('properties.show');
    Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::post('properties/{property}/archive', [PropertyController::class, 'archive'])->name('properties.archive');

    Route::get('properties/{property}/room-types', [RoomTypeController::class, 'index'])->name('properties.room-types.index');
    Route::get('properties/{property}/room-types/create', [RoomTypeController::class, 'create'])->name('properties.room-types.create');
    Route::post('properties/{property}/room-types', [RoomTypeController::class, 'store'])->name('properties.room-types.store');
    Route::get('room-types/{roomType}/edit', [RoomTypeController::class, 'edit'])->name('room-types.edit');
    Route::put('room-types/{roomType}', [RoomTypeController::class, 'update'])->name('room-types.update');
    Route::post('room-types/{roomType}/archive', [RoomTypeController::class, 'archive'])->name('room-types.archive');
    Route::get('room-types/{roomType}/prices', [RoomPriceController::class, 'indexForRoomType'])->name('room-types.prices.index');
    Route::get('room-types/{roomType}/prices/create', [RoomPriceController::class, 'createForRoomType'])->name('room-types.prices.create');
    Route::post('room-types/{roomType}/prices', [RoomPriceController::class, 'storeForRoomType'])->name('room-types.prices.store');

    Route::get('properties/{property}/rooms', [RoomController::class, 'index'])->name('properties.rooms.index');
    Route::get('properties/{property}/rooms/create', [RoomController::class, 'create'])->name('properties.rooms.create');
    Route::post('properties/{property}/rooms', [RoomController::class, 'store'])->name('properties.rooms.store');
    Route::get('rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
    Route::get('rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
    Route::put('rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::post('rooms/{room}/archive', [RoomController::class, 'archive'])->name('rooms.archive');
    Route::get('rooms/{room}/prices', [RoomPriceController::class, 'indexForRoom'])->name('rooms.prices.index');
    Route::get('rooms/{room}/prices/create', [RoomPriceController::class, 'createForRoom'])->name('rooms.prices.create');
    Route::post('rooms/{room}/prices', [RoomPriceController::class, 'storeForRoom'])->name('rooms.prices.store');

    Route::get('prices/{price}/edit', [RoomPriceController::class, 'edit'])->name('prices.edit');
    Route::put('prices/{price}', [RoomPriceController::class, 'update'])->name('prices.update');
    Route::post('prices/{price}/end', [RoomPriceController::class, 'end'])->name('prices.end');
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'verified', 'active', 'role:super_admin'])->group(function () {
    Route::get('/', PlatformDashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
