<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 预检标注：已有污渍 / 破损（与照片对应的坐标标注）
        Schema::create('garment_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');           // stain=污渍, damage=破损
            $table->string('label')->nullable();
            $table->unsignedInteger('x_permille')->default(0); // 照片坐标 0-1000
            $table->unsignedInteger('y_permille')->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // 照片：预检照片（intake）/ 完检照片（completion）/ 争议照片（dispute）
        Schema::create('garment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->index();           // intake|completion|dispute
            $table->string('path');                     // 本地存储相对路径
            $table->string('filename');                 // 原始文件名
            $table->string('checksum', 64)->index();    // sha256，防篡改校验
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            // 同一张照片文件不得作为同一衣物同阶段证据重复上传
            $table->unique(['garment_id', 'stage', 'checksum']);
        });

        // 口袋物品登记
        Schema::create('pocket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('status')->default('found'); // found=已发现待处理, returned=已归还
            $table->text('note')->nullable();
            $table->foreignId('found_by')->nullable()->constrained('users');
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pocket_items');
        Schema::dropIfExists('garment_photos');
        Schema::dropIfExists('garment_marks');
    }
};
