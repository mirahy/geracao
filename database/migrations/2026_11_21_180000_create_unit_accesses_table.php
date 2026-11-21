<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidades que cada usuário pode ver e, se can_edit, editar (além do que o perfil permite).
        Schema::create('unit_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'consumer_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_accesses');
    }
};
