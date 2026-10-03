<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

// 仅在数据库为空时写入演示数据，重启服务不会重复播种
Artisan::command('app:seed-demo', function () {
    if (User::query()->exists()) {
        $this->info('数据库已有数据，跳过演示数据播种。');

        return;
    }

    $this->call('db:seed', ['--force' => true, '--class' => DatabaseSeeder::class]);
    $this->info('演示数据已播种。');
})->purpose('数据库为空时播种演示数据');
