<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_document_id')->nullable()->constrained()->nullOnDelete();
            $table->date('reference_month');
            $table->string('invoice_number')->nullable();
            $table->string('series')->nullable();
            $table->string('access_key')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('reading_previous_date')->nullable();
            $table->date('reading_current_date')->nullable();
            $table->unsignedSmallInteger('billing_days')->nullable();
            $table->string('meter_number')->nullable();

            // Energia (kWh)
            $table->decimal('consumption_kwh', 12, 2)->default(0);      // consumo faturado
            $table->decimal('injected_kwh', 12, 2)->default(0);         // energia injetada medida (UC geradora)
            $table->decimal('compensated_kwh', 12, 2)->default(0);      // créditos compensados na fatura
            $table->decimal('credit_balance_kwh', 12, 2)->default(0);   // saldo acumulado
            $table->decimal('credit_expiring_kwh', 12, 2)->default(0);  // a expirar no próximo ciclo
            $table->string('credit_origin')->nullable();                // ex.: "mUC 9/2026 mPT"
            $table->date('credit_cycle')->nullable();

            // Valores (R$)
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('energy_amount', 12, 2)->default(0);              // consumo em kWh
            $table->decimal('compensation_credit_amount', 12, 2)->default(0); // crédito da energia injetada (positivo)
            $table->decimal('compensation_fee_amount', 12, 2)->default(0);    // ajuste GD II / Lei 14.300
            $table->decimal('cip_amount', 12, 2)->default(0);
            $table->decimal('other_charges_amount', 12, 2)->default(0);       // multa, juros, atualização
            $table->decimal('tariff_per_kwh', 12, 6)->nullable();             // tarifa com tributos
            $table->decimal('pis_cofins_total', 12, 2)->default(0);
            $table->decimal('icms_base', 12, 2)->default(0);
            $table->decimal('icms_total', 12, 2)->default(0);

            // Composição do valor
            $table->decimal('cost_distribution', 12, 2)->default(0);
            $table->decimal('cost_energy', 12, 2)->default(0);
            $table->decimal('cost_transmission', 12, 2)->default(0);
            $table->decimal('cost_charges', 12, 2)->default(0);
            $table->decimal('cost_taxes', 12, 2)->default(0);
            $table->decimal('cost_other', 12, 2)->default(0);

            $table->boolean('is_contingency')->default(false);
            $table->timestamps();

            $table->unique(['consumer_unit_id', 'reference_month']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('category'); // consumption | compensation | compensation_fee | cip | other_charge | other
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 6)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('pis_cofins_amount', 12, 2)->nullable();
            $table->decimal('icms_base', 12, 2)->nullable();
            $table->decimal('icms_rate', 6, 2)->nullable();
            $table->decimal('icms_amount', 12, 2)->nullable();
            $table->decimal('tariff', 12, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
