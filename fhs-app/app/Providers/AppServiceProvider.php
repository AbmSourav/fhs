<?php

namespace App\Providers;

use App\Enums\UserRole;
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
        // Reaching the business pages at all. Everyone with a role holds this:
        // an investor reads the same figures as a founder, they simply cannot
        // change any of them — which the 'write' gate governs, not this one.
        //
        // Named for the standing rather than the role, since it is what most
        // routes gate on. Authorise against the gate rather than calling
        // isAdmin() or reading the role, so each check has one definition.
        Gate::define('admin', static function (User $user): bool {
            if ($user->isAdmin()) {
                return true;
            }

            return $user->role() !== null;
        });

        // Changing anything: recording a sale, correcting a purchase, removing
        // an expense. An investor is a reader, so this is where they stop.
        Gate::define('write', static function (User $user): bool {
            if ($user->isAdmin()) {
                return true;
            }

            return $user->role() === UserRole::Founder;
        });

        // Narrower than both. Accounts are how access itself is granted, so
        // handing this to a founder would let them make themselves an equal, or
        // lock the administrator out. Admins are fixed by deployment, which is
        // what makes them the right holders of it.
        Gate::define('manage-users', static function (User $user): bool {
            return $user->isAdmin();
        });
    }
}
