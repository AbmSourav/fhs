<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'keramotul.islam@gmail.com'],
            [
                'name'     => 'Keramot',
                'password' => 'Sourav@619',
            ],
        );

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $user->save();
        }
    }
}
