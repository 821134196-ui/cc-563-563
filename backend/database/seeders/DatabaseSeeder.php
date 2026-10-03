<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * 初始店员账号：
     *   cashier@store.local / washer@store.local / manager@store.local
     * 初始密码统一为 password
     */
    public function run(): void
    {
        $this->call(StaffSeeder::class);
    }
}
