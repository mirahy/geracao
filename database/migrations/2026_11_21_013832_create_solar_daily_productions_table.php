<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solar_daily_productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('generated_kwh', 12, 2);
            $table->decimal('revenue', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'date']);
        });

        Schema::table('solar_generations', function (Blueprint $table) {
            // manual = informado pelo usuário; planilha = somado a partir da produção diária
            $table->string('source')->default('manual')->after('generated_kwh');
        });
    }

    public function down(): void
    {
        Schema::table('solar_generations', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::dropIfExists('solar_daily_productions');
    }
};
