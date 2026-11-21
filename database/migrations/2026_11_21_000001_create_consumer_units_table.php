<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Código da instalação impresso na fatura (ex.: 2496572-5)
            $table->string('code')->nullable()->unique();
            // Número da UC só com dígitos (ex.: 91735805198), usado nas transferências
            $table->string('uc_number')->unique();
            $table->string('role')->default('receiver'); // generator | receiver
            $table->string('holder_name')->nullable();
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('classification')->nullable();
            $table->string('meter_number')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_units');
    }
};
