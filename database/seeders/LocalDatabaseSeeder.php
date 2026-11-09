<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class LocalDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('Demo data can only be seeded in the local environment.');
        }

        User::firstOrCreate(
            ['email' => 'developer@example.test'],
            [
                'name' => 'Developer',
                'password' => '123',
            ],
        );
    }
}
