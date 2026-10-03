<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 预检时标注的已有污渍 / 破损，以及完工后新发现的痕迹
        Schema::create('marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);   // stain | damage
            $table->string('location')->default('');
            $table->text('description')->default('');
            $table->string('stage', 16)->default('intake'); // intake | postwash | recheck
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['garment_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marks');
    }
};
