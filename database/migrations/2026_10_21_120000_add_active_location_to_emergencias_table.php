<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergencias', function (Blueprint $table): void {
            $table->string('ubicacion_activa', 50)->nullable()->unique()->after('codigo_emergencia');
        });
    }

    public function down(): void
    {
        Schema::table('emergencias', function (Blueprint $table): void {
            $table->dropUnique(['ubicacion_activa']);
            $table->dropColumn('ubicacion_activa');
        });
    }
};
