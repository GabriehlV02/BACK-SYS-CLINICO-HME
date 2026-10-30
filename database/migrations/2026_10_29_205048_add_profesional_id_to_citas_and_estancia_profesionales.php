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
        foreach (['citas', 'estancia_profesionales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->uuid('profesional_id')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['citas', 'estancia_profesionales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('profesional_id');
            });
        }
    }
};
