<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('mrn', 32)->unique();
            $table->string('cpr', 32)->unique();
            $table->string('name', 150);
            $table->string('phone', 32)->index();
            $table->date('dob');
            $table->string('gender', 20);
            $table->string('flat_number', 20)->nullable();
            $table->string('building_number', 20)->nullable();
            $table->string('road_number', 20)->nullable();
            $table->string('block', 20)->nullable();
            $table->string('area', 100)->nullable();
            $table->timestamps();
            $table->index(['name', 'id']);
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_gender_check CHECK (gender IN ('male', 'female', 'other', 'unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
