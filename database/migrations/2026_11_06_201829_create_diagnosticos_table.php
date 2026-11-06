<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 25)->unique();
            $table->string('nombre', 255);
            $table->string('nombre_original', 255);
            $table->string('nombre_ingles', 255)->nullable();
            $table->text('uri_fundacion')->nullable();
            $table->string('uri_linealizacion', 190)->unique();
            $table->text('uri_padre')->nullable();
            $table->string('capitulo', 10)->nullable()->index();
            $table->unsignedTinyInteger('nivel')->nullable();
            $table->boolean('es_residual')->default(false);
            $table->boolean('es_hoja')->default(false);
            $table->boolean('tabulacion_principal')->nullable();
            $table->json('agrupaciones')->nullable();
            $table->text('nota_codificacion')->nullable();
            $table->string('version_fuente', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos');
    }
};
