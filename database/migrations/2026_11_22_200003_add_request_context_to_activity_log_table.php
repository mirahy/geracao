<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->string('causer_name')->nullable()->after('causer_id'); // nome no momento do registro (o usuário pode ser excluído depois)
            $table->string('channel', 10)->nullable()->after('batch_uuid'); // api | panel | console
            $table->string('ip', 45)->nullable()->after('channel');
            $table->string('user_agent', 255)->nullable()->after('ip');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn(['causer_name', 'channel', 'ip', 'user_agent']);
        });
    }
};
