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
            $table->string('code', 32)->unique();
            $table->string('customer_name');
            $table->string('customer_phone')->default('');
            // intake | paused | in_care | completed | closed
            $table->string('status', 16)->default('intake')->index();
            $table->string('pause_reason', 32)->nullable();
            $table->text('pause_note')->nullable();
            $table->text('resume_note')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
