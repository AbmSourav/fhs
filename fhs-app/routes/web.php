<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\InventoryController;
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

        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
