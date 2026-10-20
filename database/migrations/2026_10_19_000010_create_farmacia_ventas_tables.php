<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmacia_productos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('origen', 12); // CONTABLE o PROPIO
            $table->string('contable_producto_id', 80)->nullable()->unique();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->string('unidad', 30)->default('unidad');
            $table->decimal('precio_venta', 14, 2)->default(0);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        Schema::create('farmacia_movimientos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('producto_id');
            $table->string('tipo', 15); // INGRESO, VENTA, AJUSTE
            $table->decimal('cantidad', 14, 3);
            $table->decimal('stock_anterior', 14, 3);
            $table->decimal('stock_posterior', 14, 3);
            $table->string('referencia', 100)->nullable();
            $table->text('nota')->nullable();
            $table->string('responsable', 120)->nullable();
            $table->timestamps();
            $table->foreign('producto_id')->references('id')->on('farmacia_productos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmacia_movimientos');
        Schema::dropIfExists('farmacia_productos');
    }
};
