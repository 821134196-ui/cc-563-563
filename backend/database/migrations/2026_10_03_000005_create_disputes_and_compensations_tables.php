<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('type');              // new_damage=新增破损, new_stain=新增污渍, missing=缺件, other
            $table->text('description');
            $table->unsignedBigInteger('requested_amount')->default(0); // 协商金额（分）
            // open=待处理, rechecking=复检中, negotiating=赔偿协商, resolved=已解决, rejected=驳回
            $table->string('status')->default('open')->index();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        // 复检记录（可多轮）
        Schema::create('dispute_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users'); // 复检人
            $table->string('result');          // confirmed=确认差异, not_found=未见差异, inconclusive=存疑
            $table->text('finding');
            $table->json('evidence_photo_ids')->nullable();
            $table->timestamps();
        });

        // 赔付：一件争议最多一条有效赔付（DB 层唯一约束）
        Schema::create('compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->unique(); // 一争议一赔付，拦截重复赔付请求
            $table->foreignId('garment_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->unsignedBigInteger('amount');              // 金额（分）
            $table->string('currency', 3)->default('CNY');
            // requested=待审批, approved=已批准待付, rejected=已驳回, paid=已赔付, failed=支付失败
            $table->string('status')->default('requested')->index();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 赔付回执（模拟接口）：幂等键唯一，重复回执不会二次付款
        Schema::create('compensation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_id')->constrained('compensations')->cascadeOnDelete();
            $table->string('receipt_no')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('gateway')->default('mock-gateway');
            $table->string('gateway_status');
            $table->json('payload')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // 统一审计事件流（责任处理过程可追溯）
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('garment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('event_type')->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('compensation_receipts');
        Schema::dropIfExists('compensations');
        Schema::dropIfExists('dispute_reviews');
        Schema::dropIfExists('disputes');
    }
};
