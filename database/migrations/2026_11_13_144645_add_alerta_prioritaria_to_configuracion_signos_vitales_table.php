<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('configuracion_signos_vitales', function (Blueprint $table): void {
            $table->boolean('alerta_prioritaria')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configuracion_signos_vitales', function (Blueprint $table): void {
            $table->dropColumn('alerta_prioritaria');
        });
    }
};
