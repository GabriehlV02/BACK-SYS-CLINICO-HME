<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudios_radiografia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('estudio_id')->unique();
            $table->json('contenido')->nullable();
            $table->json('archivos')->nullable();
            $table->text('observaciones_tecnicas')->nullable();
            $table->timestamps();
            $table->foreign('estudio_id')->references('id')->on('estudios')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estudios_radiografia');
    }
};
