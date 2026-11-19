<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('patient_year_totals', function (Blueprint $table) {
            $table->unsignedSmallInteger('year');
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_count')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->dateTime('updated_at');
            $table->primary(['year', 'patient_id']);
        });
        DB::statement('CREATE INDEX patient_year_totals_ranking ON patient_year_totals (year, net_minor DESC, patient_id)');
    }
    public function down(): void
    {
        Schema::dropIfExists('patient_year_totals');
    }
};
