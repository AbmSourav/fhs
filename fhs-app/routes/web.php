<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Catalogue management is admin-only. The shared `auth.isAdmin` flag hides
    // the menu item, but authorisation is enforced here on the server.
    Route::middleware('can:admin')->group(function () {
        Route::get('catalogue', [CatalogueController::class, 'index'])->name('catalogue.index');
        Route::get('catalogue/setup', [CatalogueController::class, 'setup'])->name('catalogue.setup');
        Route::post('catalogue', [CatalogueController::class, 'store'])->name('catalogue.store');

        Route::get('inventories', [InventoryController::class, 'index'])->name('inventories.index');
        Route::get('inventories/add', [InventoryController::class, 'create'])->name('inventories.create');
        Route::post('inventories', [InventoryController::class, 'store'])->name('inventories.store');

        Route::get('inventories/edit/{kind}/{id}', [InventoryController::class, 'edit'])
            ->whereNumber('id')
            ->name('inventories.edit');

        Route::patch('inventories/update/{kind}/{id}', [InventoryController::class, 'update'])
            ->whereNumber('id')
            ->name('inventories.update');

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->name('customers.history');
        Route::get('customers/edit/{customer}', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('customers/update/{customer}', [CustomerController::class, 'update'])->name('customers.update');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/add', [OrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/pay/{order}', [OrderController::class, 'pay'])->name('orders.pay');
        Route::post('orders/pay/{order}', [OrderController::class, 'storePayment'])->name('orders.pay.store');
        Route::get('orders/edit/{order}', [OrderController::class, 'edit'])->name('orders.edit');
        Route::patch('orders/update/{order}', [OrderController::class, 'update'])->name('orders.update');
        // Called while the form is open, so it answers with JSON rather than a
        // full Inertia page.
        Route::get('orders/customer-lookup', [OrderController::class, 'lookupCustomer'])
            ->name('orders.customer-lookup');

        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
