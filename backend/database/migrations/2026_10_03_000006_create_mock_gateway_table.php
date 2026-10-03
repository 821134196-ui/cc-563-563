<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 模拟支付网关自己的交易台账（与业务库隔离语义）
        Schema::create('mock_gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no')->unique();
            $table->string('payment_ref')->unique();        // 对应赔付单（一件赔付最多一笔网关交易）
            $table->string('idempotency_key')->unique();    // 网关侧幂等键
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('CNY');
            $table->string('status')->default('success');   // 模拟网关始终受理成功
            $table->unsignedInteger('callback_count')->default(0); // 收到回执次数（重复回执不二次付款）
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_gateway_transactions');
    }
};
