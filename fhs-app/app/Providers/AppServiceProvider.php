<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Authorise against this gate rather than calling isAdmin() directly,
        // so the admin check has a single definition to change later.
        Gate::define('admin', static fn (User $user): bool => $user->isAdmin());
    }
}
