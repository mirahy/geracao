<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_document_id')->nullable()->constrained()->nullOnDelete();
            $table->date('reference_month');
            $table->decimal('balance_previous_kwh', 12, 2)->default(0);
            $table->decimal('injected_kwh', 12, 2)->default(0);
            $table->decimal('received_kwh', 12, 2)->default(0);
            $table->decimal('compensated_kwh', 12, 2)->default(0);
            $table->decimal('transferred_kwh', 12, 2)->default(0);
            $table->decimal('expired_kwh', 12, 2)->default(0);
            $table->decimal('balance_available_kwh', 12, 2)->default(0);
            $table->decimal('cycle_consumption_kwh', 12, 2)->default(0);
            $table->string('expiration_cycle')->nullable();
            $table->json('previous_balance_composition')->nullable();
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'reference_month']);
        });

        Schema::create('energy_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('measured_kwh', 12, 2)->default(0);
            $table->decimal('irregular_kwh', 12, 2)->default(0);
            $table->decimal('injected_kwh', 12, 2)->default(0);
            $table->decimal('compensated_kwh', 12, 2)->default(0);
            $table->decimal('surplus_kwh', 12, 2)->default(0);
            $table->decimal('gross_kwh', 12, 2)->default(0);
            $table->date('source_reference')->nullable(); // demonstrativo de onde veio
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'reference_month']);
        });

        Schema::create('credit_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('energy_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_unit_id')->constrained('consumer_units')->cascadeOnDelete();
            $table->foreignId('target_unit_id')->constrained('consumer_units')->cascadeOnDelete();
            $table->date('reference_month');
            $table->date('cycle_month')->nullable();
            $table->unsignedSmallInteger('percentage')->default(0);
            $table->decimal('kwh', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['energy_statement_id', 'target_unit_id']);
        });

        Schema::create('solar_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('generated_kwh', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'reference_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_generations');
        Schema::dropIfExists('credit_transfers');
        Schema::dropIfExists('energy_histories');
        Schema::dropIfExists('energy_statements');
    }
};
