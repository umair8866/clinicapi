<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branch_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
            $table->unique(['branch_id', 'day_of_week', 'opens_at']);
        });

        DB::statement('ALTER TABLE branch_hours ADD CONSTRAINT branch_hours_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement("ALTER TABLE branch_hours ADD CONSTRAINT branch_hours_time_check CHECK (opens_at >= '00:00:00' AND opens_at < closes_at AND closes_at <= '24:00:00')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_hours');
    }
};
