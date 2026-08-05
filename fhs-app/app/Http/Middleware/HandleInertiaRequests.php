<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name'  => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth'  => [
                'user' => $request->user(),
                // Drives admin-only UI. The frontend flag is a convenience for
                // rendering; every privileged route must still authorise on the
                // server via the "admin" gate.
                'isAdmin' => (bool) $request->user()?->isAdmin(),
                // So a button the server would refuse is not offered in the
                // first place. An investor sees every page but no Add, Edit or
                // Delete; the read-only middleware is what actually stops them.
                'canWrite'       => Gate::allows('write'),
                'canManageUsers' => Gate::allows('manage-users'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
            'userRoles' => UserRole::roles(),
        ]);
    }
}
