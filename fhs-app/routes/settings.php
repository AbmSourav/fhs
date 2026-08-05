<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // Creating accounts is an administrator's job alone — not a founder's.
    // Accounts are how access itself is granted, so a founder who could create
    // them could make themselves an equal, or lock the administrator out.
    Route::middleware('can:manage-users')->group(function () {
        Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
        Route::post('settings/users', [UserController::class, 'store'])->name('users.store');
        Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
