<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('filters');
            $table->json('branch_scope');
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('max_visit_id');
            $table->dateTime('last_visit_date')->nullable();
            $table->unsignedBigInteger('last_visit_id')->nullable();
            $table->unsignedInteger('parts')->default(0);
            $table->string('object_key', 512)->unique();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['user_id','created_at']);
            $table->index(['status','updated_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
