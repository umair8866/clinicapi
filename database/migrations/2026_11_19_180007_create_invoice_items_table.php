<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('service_code', 40);
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_minor');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_amounts_check CHECK (qty > 0 AND unit_price_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
