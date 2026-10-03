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
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->default('');
            // washer | customer | store | unknown
            $table->string('suspected_responsibility', 16)->default('unknown');
            // open | rechecking | negotiating | approved | rejected | paid | closed
            $table->string('status', 16)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('compensation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->text('reason')->default('');
            // pending | approved | rejected | paid
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_id')->unique()->constrained('compensation_requests')->cascadeOnDelete();
            $table->string('receipt_no', 64)->unique();
            $table->string('idempotency_key', 96)->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('gateway', 24)->default('mock');
            $table->string('status', 16)->default('paid');
            $table->json('response_payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 全流程审计：暂停 / 恢复 / 确认 / 复检 / 审批 / 付款，作为责任处理证据链
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('garment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48)->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('payout_receipts');
        Schema::dropIfExists('compensation_requests');
        Schema::dropIfExists('dispute_notes');
        Schema::dropIfExists('disputes');
    }
};
