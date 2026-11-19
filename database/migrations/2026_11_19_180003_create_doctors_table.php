<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('specialty', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
