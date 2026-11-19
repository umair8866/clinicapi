<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('seed_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 64)->unique();
            $table->json('config');
            $table->char('config_hash', 64);
            $table->string('phase', 30);
            $table->unsignedBigInteger('last_committed_id')->default(0);
            $table->json('row_counts');
            $table->json('metrics');
            $table->string('status', 20);
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('seed_runs');
    }
};
