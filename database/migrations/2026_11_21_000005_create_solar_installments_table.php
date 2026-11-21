<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solar_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('start_month');
            $table->date('end_month')->nullable(); // null = sem data final (continua)
            $table->decimal('amount', 12, 2);      // valor pago por mês
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_installments');
    }
};
