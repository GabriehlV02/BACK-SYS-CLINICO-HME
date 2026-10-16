<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->string('caja', 100)->nullable()->after('correo');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios_sistema', function (Blueprint $table): void {
            $table->dropColumn('caja');
        });
    }
};
