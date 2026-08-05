<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /** Everyone with an account, and the form for adding another. */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/users', [
            'users' => $this->users->all(),
            'roles' => UserRole::options(),
            // So the interface can disable a delete it knows will be refused,
            // rather than offering a button that only fails on submission.
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->users->rules(),
            $this->users->messages(),
        );

        $user = $this->users->create($data);

        return to_route('users.index')
            ->with('success', "{$user->name} can now sign in.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $reason = $this->users->deleteBlockedReason($user, $request->user());

        if ($reason !== null) {
            return back()->with('error', $reason);
        }

        $name = $user->name;

        $this->users->delete($user, $request->user());

        return to_route('users.index')
            ->with('success', "{$name}’s account was removed.");
    }
}
