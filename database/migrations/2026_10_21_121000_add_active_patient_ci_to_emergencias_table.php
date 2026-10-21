<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergencias', function (Blueprint $table): void {
            $table->string('paciente_activo_ci', 80)->nullable()->unique()->after('ubicacion_activa');
        });
    }

    public function down(): void
    {
        Schema::table('emergencias', function (Blueprint $table): void {
            $table->dropUnique(['paciente_activo_ci']);
            $table->dropColumn('paciente_activo_ci');
        });
    }
};
