<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            // intake（原始预检） | postwash（完工） | recheck（复检）
            $table->string('stage', 16)->index();
            $table->string('disk_path');
            $table->string('original_name')->default('');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('mime', 64)->default('application/octet-stream');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['garment_id', 'stage']);
        });

        // 同一张原始预检照片（按内容哈希）不能用于第二件衣物的入库，
        // 从数据层阻止“同一衣物重复入库”。
        DB::statement(
            "CREATE UNIQUE INDEX photos_intake_hash_unique ON photos (sha256) WHERE stage = 'intake'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
