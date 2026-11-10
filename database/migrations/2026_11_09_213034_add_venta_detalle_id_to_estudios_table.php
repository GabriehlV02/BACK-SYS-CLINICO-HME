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
        Schema::table('estudios', function (Blueprint $table) {
            $table->uuid('venta_detalle_id')->nullable();
            $table->unsignedInteger('venta_detalle_unidad')->nullable();
            $table->unique(['venta_detalle_id', 'venta_detalle_unidad'], 'estudios_venta_detalle_unidad_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $table) {
            $table->dropUnique('estudios_venta_detalle_unidad_unique');
            $table->dropColumn(['venta_detalle_id', 'venta_detalle_unidad']);
        });
    }
};
