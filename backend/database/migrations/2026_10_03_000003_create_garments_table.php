<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // 全局唯一衣物编号（吊牌号）——同一衣物不能重复入库
            $table->string('tag_no')->unique();
            $table->string('category')->nullable();      // 品类：外套/衬衫…
            $table->string('color')->nullable();
            $table->string('brand')->nullable();
            $table->json('care_labels')->nullable();     // 护理标签：no_wash,hand_wash,no_bleach,low_heat...
            $table->text('description')->nullable();     // 面料/备注

            // intake=预检中, confirmed=已确认, washing=洗护中,
            // ready_inspection=待完检, inspection_passed=完检通过,
            // disputed=有争议, rechecking=复检中, completed=已交付
            $table->string('status')->default('intake')->index();

            $table->foreignId('washer_id')->nullable()->constrained('users'); // 洗护责任人
            $table->timestamp('wash_started_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users');

            // 预检锁定：顾客确认后预检证据冻结
            $table->timestamp('intake_locked_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garments');
    }
};
