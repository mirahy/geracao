<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Decisão do administrador sobre o pedido (status: pending | approved | rejected | cancelled).
        Schema::table('unit_requests', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decided_at', 'decision_note']);
        });
    }
};
