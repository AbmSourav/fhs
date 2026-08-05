<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Managing who can sign in.
 *
 * Accounts are created here and the credentials passed on out of band — there
 * is no self-registration for founders or investors, and no invitation email.
 */
class UserService
{
    /** Everyone with an account, newest first. */
    public function all(): array
    {
        return User::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (User $user) => $this->present($user))
            ->all();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            // Laravel's default strength rules, the same ones the password
            // reset form applies.
            'password' => ['required', 'confirmed', Password::defaults()],
            'role'     => ['required', Rule::enum(UserRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'      => 'Enter the person’s name.',
            'email.required'     => 'Enter an email address — it is what they sign in with.',
            'email.unique'       => 'Someone already has an account with this email.',
            'email.lowercase'    => 'Enter the email address in lower case.',
            'password.required'  => 'Set a password to pass on to them.',
            'password.confirmed' => 'The two passwords do not match.',
            'role.required'      => 'Choose what kind of user this is.',
        ];
    }

    /**
     * Create an account.
     *
     * Verified on creation: an administrator entering these details has
     * confirmed the address by hand, and there is no inbox to send to for a
     * staff account handed over in person.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name'     => trim($data['name']),
                'email'    => strtolower(trim($data['email'])),
                'password' => $data['password'],
            ]);

            // Set after creation rather than in the array above: `permission`
            // is deliberately not mass-assignable.
            $user->assignRole(UserRole::from($data['role']));
            $user->email_verified_at = now();
            $user->save();

            return $user;
        });
    }

    /**
     * Why this account cannot be deleted, or null if it can.
     *
     * Stated as a reason rather than a boolean so the interface can say why the
     * button is disabled instead of leaving someone guessing.
     */
    public function deleteBlockedReason(User $user, User $actor): ?string
    {
        if ($user->is($actor)) {
            return 'You cannot delete your own account.';
        }

        // An admin's standing comes from config, so deleting the row would not
        // remove their access — it would only strand it without an account.
        if ($user->isAdmin()) {
            return 'Administrators are set by deployment and cannot be deleted here.';
        }

        return null;
    }

    /** Remove an account, with the guards applied. */
    public function delete(User $user, User $actor): void
    {
        $reason = $this->deleteBlockedReason($user, $actor);

        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        $user->delete();
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role()?->value,
            // Null for an account created before roles existed, and for
            // administrators, whose standing comes from config.
            'role_label' => $user->role()?->label(),
            'is_admin'   => $user->isAdmin(),
            'verified'   => $user->email_verified_at !== null,
            'created_at' => $user->created_at,
        ];
    }
}
