<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('city', 100);
            $table->string('timezone', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'branch_id']);
            $table->index(['branch_id', 'user_id']);
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('specialty', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'branch_id']);
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('mrn', 32)->unique();
            $table->string('cpr', 32)->unique();
            $table->string('name', 150);
            $table->string('phone', 32)->index();
            $table->date('dob');
            $table->string('gender', 20);
            $table->timestamps();
            $table->index(['name', 'id']);
        });

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

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('service_code', 40);
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_minor');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->bigInteger('amount_minor');
            $table->dateTime('paid_at');
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_gender_check CHECK (gender IN ('male', 'female', 'other', 'unknown'))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('scheduled', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('unpaid', 'partially_paid', 'paid', 'void'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (total_minor >= 0 AND discount_minor >= 0 AND discount_minor <= total_minor AND vat_minor >= 0 AND net_minor = total_minor - discount_minor + vat_minor AND vat_rate_bps <= 10000)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_amounts_check CHECK (qty > 0 AND unit_price_minor >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'card', 'insurance'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_minor > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
