<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('doctor_id');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('visit_date');
            $table->string('status', 20);
            $table->timestamps();
            $table->foreign(['doctor_id', 'branch_id'])->references(['id', 'branch_id'])->on('doctors')->restrictOnDelete();
            $table->index(['doctor_id', 'branch_id']);
            $table->index(['patient_id', 'visit_date', 'id']);
            $table->index(['visit_date', 'id']);
            $table->index(['branch_id', 'visit_date', 'id']);
            $table->index(['doctor_id', 'visit_date', 'id']);
            $table->index(['status', 'visit_date', 'id']);
        });

        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('scheduled', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
