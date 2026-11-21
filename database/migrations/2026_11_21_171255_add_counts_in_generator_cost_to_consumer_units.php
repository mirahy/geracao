<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_units', function (Blueprint $table) {
            // Se a fatura da UC beneficiária entra no cálculo do custo do kWh da UC geradora
            $table->boolean('counts_in_generator_cost')->default(false)->after('bills_solar_cost');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_units', function (Blueprint $table) {
            $table->dropColumn('counts_in_generator_cost');
        });
    }
};
