<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmacia_almacen_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('item_venta_id')->nullable();
            $table->string('nombre', 160);
            $table->string('codigo', 50)->unique();
            $table->string('tipo', 20);
            $table->string('area_uso', 30);
            $table->string('unidad', 30)->default('unidad');
            $table->decimal('cantidad', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('item_venta_id')->references('id')->on('farmacia_productos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmacia_almacen_items');
    }
};
