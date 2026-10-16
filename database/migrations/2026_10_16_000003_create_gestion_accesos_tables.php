<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_usuarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 80);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 60)->unique();
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('permisos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 100)->unique();
            $table->string('nombre', 120);
            $table->string('modulo', 80);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('permiso_rol', function (Blueprint $table): void {
            $table->uuid('rol_id');
            $table->uuid('permiso_id');
            $table->primary(['rol_id', 'permiso_id']);
            $table->foreign('rol_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permiso_id')->references('id')->on('permisos')->cascadeOnDelete();
        });

        Schema::create('rol_usuario', function (Blueprint $table): void {
            $table->uuid('usuario_id');
            $table->uuid('rol_id');
            $table->primary(['usuario_id', 'rol_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios_sistema')->cascadeOnDelete();
            $table->foreign('rol_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->uuid('tipo_usuario_id')->nullable()->after('tipo_usuario');
            $table->foreign('tipo_usuario_id')->references('id')->on('tipos_usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->dropForeign(['tipo_usuario_id']);
            $table->dropColumn('tipo_usuario_id');
        });
        Schema::dropIfExists('rol_usuario');
        Schema::dropIfExists('permiso_rol');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tipos_usuarios');
    }
};
