<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 完工检查 / 复检记录
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);      // postwash | recheck
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result', 16);    // consistent | discrepancy
            $table->text('notes')->default('');
            $table->timestamps();

            $table->index(['garment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
