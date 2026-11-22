<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pagamentos do rateio feitos pelas unidades beneficiárias. Um mês pode ter vários pagamentos (parciais).
        Schema::create('rateio_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('amount', 12, 2);
            $table->dateTime('paid_at')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('transaction_id', 100)->nullable()->index();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('source', 20)->default('manual');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consumer_unit_id', 'reference_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rateio_payments');
    }
};
