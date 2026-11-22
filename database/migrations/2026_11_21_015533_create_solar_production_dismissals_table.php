<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pendências da importação da planilha que o usuário descartou. Voltam a aparecer
        // se uma nova planilha mudar a soma ou a quantidade de dias do período.
        Schema::create('solar_production_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('generated_kwh', 12, 2);
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'reference_month'], 'solar_dismissals_unit_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_production_dismissals');
    }
};
