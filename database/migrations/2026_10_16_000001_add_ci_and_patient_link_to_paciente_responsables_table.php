<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paciente_responsables', function (Blueprint $table): void {
            $table->string('ci', 25)->nullable()->after('nombre_completo');
            $table->uuid('paciente_vinculado_id')->nullable()->after('paciente_id')->index();
            $table->foreign('paciente_vinculado_id')->references('id')->on('pacientes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paciente_responsables', function (Blueprint $table): void {
            $table->dropForeign(['paciente_vinculado_id']);
            $table->dropIndex(['paciente_vinculado_id']);
            $table->dropColumn(['ci', 'paciente_vinculado_id']);
        });
    }
};
