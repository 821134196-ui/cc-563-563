<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['name' => '王收件', 'email' => 'cashier@store.local', 'role' => User::ROLE_CASHIER],
            ['name' => '李洗护', 'email' => 'washer@store.local', 'role' => User::ROLE_WASHER],
            ['name' => '赵店长', 'email' => 'manager@store.local', 'role' => User::ROLE_MANAGER],
        ];

        foreach ($staff as $person) {
            User::updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'role' => $person['role'],
                    'password' => Hash::make('password'),
                ],
            );
        }
    }
}
