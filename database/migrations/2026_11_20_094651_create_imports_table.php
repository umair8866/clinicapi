<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('object_key', 512)->unique();
            $table->string('error_object_key', 512)->nullable();
            $table->unsignedBigInteger('source_bytes');
            $table->unsignedBigInteger('next_byte_offset')->default(0);
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('valid_rows')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['user_id','created_at']);
            $table->index(['status','updated_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
