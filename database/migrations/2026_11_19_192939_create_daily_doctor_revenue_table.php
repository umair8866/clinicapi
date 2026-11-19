<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('daily_doctor_revenue', function (Blueprint $table) {
            $table->date('billing_date');
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->dateTime('updated_at');
            $table->primary(['billing_date', 'doctor_id']);
            $table->index(['branch_id', 'billing_date', 'doctor_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('daily_doctor_revenue');
    }
};
