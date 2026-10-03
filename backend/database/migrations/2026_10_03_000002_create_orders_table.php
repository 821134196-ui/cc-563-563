<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            // intake=收件预检中, awaiting_confirmation=待顾客确认, in_wash=洗护中,
            // paused=暂停, ready=待完检, completed=已完成, cancelled=已取消
            $table->string('status')->default('intake')->index();
            $table->string('status_before_pause')->nullable();

            // 暂停信息（拒签 / 禁止水洗 / 口袋物品）
            $table->string('pause_reason')->nullable();
            $table->text('pause_note')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->string('signature_name')->nullable();   // 顾客确认签名（姓名）
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
