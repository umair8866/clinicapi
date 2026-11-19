<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('daily_branch_revenue', function (Blueprint $table) {
            $table->date('billing_date');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->dateTime('updated_at');
            $table->primary(['billing_date', 'branch_id']);
            $table->index(['branch_id', 'billing_date']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('daily_branch_revenue');
    }
};
