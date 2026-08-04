<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

        Route::get('statistics', StatisticsController::class)->name('statistics');

        Route::get('reports', ReportController::class)->name('reports');
        // Built server-side from the database, so the figures cannot be edited
        // on the way out the way a printed page can.
        Route::get('reports/download', [ReportController::class, 'download'])->name('reports.download');
        // The same PDF rendered in the browser, for working on the template.
        Route::get('reports/preview', [ReportController::class, 'preview'])->name('reports.preview');

        Route::get('crm', [CrmController::class, 'index'])->name('crm');
        // Logs the call, then hands off to the write-up form.
        Route::post('crm/{customer}/call', [CrmController::class, 'call'])->name('crm.call');
        Route::get('crm/{customer}/followup/{followUp}', [CrmController::class, 'followUp'])->name('crm.follow-up');
        Route::post('crm/{customer}/followup/{followUp}', [CrmController::class, 'storeFollowUp'])->name('crm.follow-up.store');

        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('expenses/add', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('expenses/edit/{expense}', [ExpenseController::class, 'edit'])->name('expenses.edit');
        Route::patch('expenses/update/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
