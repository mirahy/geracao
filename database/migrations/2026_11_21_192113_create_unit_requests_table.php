<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pedido de um usuário novo para ser atribuído a uma unidade consumidora.
        Schema::create('unit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status')->default('pending'); // pending | approved
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_requests');
    }
};
