<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_historia_clinica', function (Blueprint $table): void {
            $table->dropForeign(['emergencia_id']);
            $table->dropIndex(['emergencia_id']);
            $table->uuid('emergencia_id')->nullable()->change();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->cascadeOnDelete();
            $table->string('referencia_id', 100)->nullable();
            $table->unique(['paciente_id', 'tipo', 'referencia_id'], 'documentos_historia_clinica_referencia_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_historia_clinica', function (Blueprint $table): void {
            $table->dropUnique('documentos_historia_clinica_referencia_unique');
            $table->dropColumn('referencia_id');
            $table->dropForeign(['emergencia_id']);
            $table->uuid('emergencia_id')->nullable(false)->change();
            $table->foreign('emergencia_id')->references('id')->on('emergencias')->cascadeOnDelete();
        });
    }
};
