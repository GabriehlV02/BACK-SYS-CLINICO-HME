<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 120); $table->string('codigo', 30)->unique();
            $table->string('sucursal', 120); $table->text('descripcion')->nullable();
            $table->string('estado', 15)->default('ACTIVO'); $table->timestamps();
        });
        Schema::create('cajas', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->string('nombre', 120);
            $table->string('sucursal', 120); $table->uuid('almacen_principal_id');
            $table->json('tipos_item_permitidos')->nullable(); $table->unsignedInteger('limite_items')->nullable();
            $table->string('estado', 15)->default('ACTIVO'); $table->timestamps();
            $table->foreign('almacen_principal_id')->references('id')->on('almacenes');
        });
        Schema::create('caja_almacen', function (Blueprint $table): void {
            $table->uuid('caja_id'); $table->uuid('almacen_id'); $table->primary(['caja_id', 'almacen_id']);
            $table->foreign('caja_id')->references('id')->on('cajas')->cascadeOnDelete();
            $table->foreign('almacen_id')->references('id')->on('almacenes');
        });
        Schema::create('almacen_existencias', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->uuid('almacen_id');
            $table->string('item_tipo', 30); $table->uuid('item_id'); $table->string('lote', 80)->nullable();
            $table->decimal('cantidad', 14, 3)->default(0); $table->decimal('reservado', 14, 3)->default(0);
            $table->date('vence_en')->nullable(); $table->timestamps();
            $table->foreign('almacen_id')->references('id')->on('almacenes');
            $table->unique(['almacen_id', 'item_id', 'lote']);
        });
        Schema::table('usuarios_sistema', function (Blueprint $table): void { $table->uuid('caja_id')->nullable()->after('caja')->index(); });
    }
    public function down(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void { $table->dropColumn('caja_id'); });
        Schema::dropIfExists('almacen_existencias'); Schema::dropIfExists('caja_almacen'); Schema::dropIfExists('cajas'); Schema::dropIfExists('almacenes');
    }
};
