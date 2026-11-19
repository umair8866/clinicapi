<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained()->restrictOnDelete();
            $table->date('billing_date');
            $table->char('currency', 3);
            $table->bigInteger('total_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->unsignedSmallInteger('vat_rate_bps');
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('net_minor');
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['billing_date', 'id']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('unpaid', 'partially_paid', 'paid', 'void'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (total_minor >= 0 AND discount_minor >= 0 AND discount_minor <= total_minor AND vat_minor >= 0 AND net_minor = total_minor - discount_minor + vat_minor AND vat_rate_bps <= 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
