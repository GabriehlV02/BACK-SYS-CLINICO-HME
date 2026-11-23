<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE cajas, caja_almacen IN ACCESS EXCLUSIVE MODE');
            }
            foreach (['cajas', 'caja_almacen'] as $tabla) {
                if (DB::table($tabla)->exists()) {
                    throw new \RuntimeException("La tabla clínica {$tabla} contiene datos. Reconcílialos con Contabilidad antes de retirarla.");
                }
            }
            Schema::drop('caja_almacen');
            Schema::drop('cajas');
        });
    }

    public function down(): void
    {
        Schema::create('cajas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 120);
            $table->string('sucursal', 120);
            $table->unsignedBigInteger('almacen_principal_id');
            $table->json('tipos_item_permitidos')->nullable();
            $table->unsignedInteger('limite_items')->nullable();
            $table->string('estado', 15)->default('ACTIVO');
            $table->timestamps();
        });
        Schema::create('caja_almacen', function (Blueprint $table): void {
            $table->foreignUuid('caja_id')->constrained('cajas')->cascadeOnDelete();
            $table->unsignedBigInteger('almacen_id');
            $table->primary(['caja_id', 'almacen_id']);
        });
    }
};
