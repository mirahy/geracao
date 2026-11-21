<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_units', function (Blueprint $table) {
            // Se a UC beneficiária paga a sua parte do custo das placas (rateio sobre a energia transferida)
            $table->boolean('bills_solar_cost')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_units', function (Blueprint $table) {
            $table->dropColumn('bills_solar_cost');
        });
    }
};
