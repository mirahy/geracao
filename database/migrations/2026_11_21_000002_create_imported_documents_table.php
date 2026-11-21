<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_documents', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path')->nullable();
            $table->string('hash', 64)->unique();
            $table->string('type')->nullable(); // invoice | statement
            $table->string('status')->default('pending'); // success | failed
            $table->text('message')->nullable();
            $table->foreignId('consumer_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->date('reference_month')->nullable();
            $table->json('parsed')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_documents');
    }
};
