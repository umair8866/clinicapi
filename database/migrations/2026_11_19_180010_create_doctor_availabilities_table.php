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
        Schema::create('doctor_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->unique(['doctor_id', 'day_of_week', 'starts_at']);
        });

        DB::statement('ALTER TABLE doctor_availabilities ADD CONSTRAINT doctor_availabilities_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement("ALTER TABLE doctor_availabilities ADD CONSTRAINT doctor_availabilities_time_check CHECK (starts_at >= '00:00:00' AND starts_at < ends_at AND ends_at <= '24:00:00')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_availabilities');
    }
};
