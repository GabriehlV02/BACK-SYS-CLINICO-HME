<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistemas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('rol_sistema', function (Blueprint $table): void {
            $table->uuid('rol_id');
            $table->uuid('sistema_id');
            $table->primary(['rol_id', 'sistema_id']);
            $table->foreign('rol_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('sistema_id')->references('id')->on('sistemas')->cascadeOnDelete();
        });

        Schema::table('permisos', function (Blueprint $table): void {
            $table->uuid('sistema_id')->nullable()->index();
            $table->foreign('sistema_id')->references('id')->on('sistemas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('permisos', function (Blueprint $table): void {
            $table->dropForeign(['sistema_id']);
            $table->dropColumn('sistema_id');
        });
        Schema::dropIfExists('rol_sistema');
        Schema::dropIfExists('sistemas');
    }
};
