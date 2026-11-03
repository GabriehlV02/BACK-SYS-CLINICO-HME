<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auditorias_usuarios', function (Blueprint $table): void {
            $table->uuid('paciente_id')->nullable()->index();
            $table->string('servicio', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('auditorias_usuarios', function (Blueprint $table): void {
            $table->dropColumn(['paciente_id', 'servicio']);
        });
    }
};
