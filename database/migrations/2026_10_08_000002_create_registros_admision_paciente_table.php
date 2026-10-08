<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('registros_admision_paciente', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('paciente_id')->index();
            $t->string('como_se_entero', 100);
            $t->text('observaciones')->nullable();
            $t->boolean('habilitado')->default(true);
            $t->timestamps();
            $t->foreign('paciente_id')->references('id')->on('pacientes')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('registros_admision_paciente'); }
};
