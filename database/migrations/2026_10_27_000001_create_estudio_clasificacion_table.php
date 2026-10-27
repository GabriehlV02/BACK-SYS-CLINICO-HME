<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudio_clasificacion', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('estudio_clasificacion')->insert([
            ['nombre' => 'Ecografía', 'orden' => 1, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Radiografía', 'orden' => 2, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Tomografía', 'orden' => 3, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Electrocardiograma', 'orden' => 4, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('estudio_clasificacion');
    }
};
