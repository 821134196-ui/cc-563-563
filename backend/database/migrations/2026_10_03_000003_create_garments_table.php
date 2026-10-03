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
            // 衣物标签号，全店唯一，用于防止重复入库
            $table->string('tag', 32)->unique();
            $table->string('category')->default('');
            $table->string('color')->default('');
            $table->string('description')->default('');
            // 护理标签：no_water_wash / no_dry_clean / no_bleach / hand_wash_only / low_temp_iron / shade_dry
            $table->json('care_labels')->nullable();
            $table->boolean('pocket_items_found')->default(false);
            $table->text('pocket_items_note')->nullable();
            // recorded | confirmed | washing | washed | disputed | done
            $table->string('status', 16)->default('recorded')->index();
            $table->foreignId('washer_id')->nullable()->constrained('users')->nullOnDelete();
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
