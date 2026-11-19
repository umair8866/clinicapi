<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->bigInteger('amount_minor');
            $table->dateTime('paid_at');
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'card', 'insurance'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
