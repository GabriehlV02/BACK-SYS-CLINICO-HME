<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->string('apellido_paterno', 100)->nullable()->after('nombres');
            $table->string('apellido_materno', 100)->nullable()->after('apellido_paterno');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->dropColumn(['apellido_paterno', 'apellido_materno']);
        });
    }
};
