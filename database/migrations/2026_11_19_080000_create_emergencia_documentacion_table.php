<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emergencia_documentacion', function (Blueprint $table): void {
            $table->foreignUuid('emergencia_id')->primary()->constrained('emergencias')->restrictOnDelete();
            $table->json('anamnesis')->nullable();
            $table->json('revision')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('emergencia_documentacion'); }
};
