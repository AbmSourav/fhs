<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * "permission" is deliberately excluded: it is a privilege field, and
     * mass-assigning it from request data would allow privilege escalation.
     * Assign it explicitly instead.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permission' => 'array',
        ];
    }

    /**
     * Determine whether this user is an administrator.
     *
     * Administrators are identified by email address via config, so the set of
     * admins is fixed by deployment rather than editable in the application.
     */
    public function isAdmin(): bool
    {
        $email = strtolower(trim((string) $this->email));

        return $email !== ''
            && in_array($email, config('app.admin_emails', []), strict: true);
    }
}
