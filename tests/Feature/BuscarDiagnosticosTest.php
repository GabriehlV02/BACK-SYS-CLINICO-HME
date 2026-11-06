<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuscarDiagnosticosTest extends TestCase
{
    public function test_busca_por_codigo_y_nombre(): void
    {
        Schema::dropIfExists('diagnosticos');
        (require database_path('migrations/2026_11_06_201829_create_diagnosticos_table.php'))->up();
        (require database_path('migrations/2026_11_06_202402_add_idioma_nombre_to_diagnosticos_table.php'))->up();
        DB::table('diagnosticos')->insert([
            ['codigo' => '1A00', 'nombre' => 'Cólera', 'nombre_original' => 'Cólera', 'uri_linealizacion' => 'uri:1'],
            ['codigo' => '1B00', 'nombre' => 'Tuberculosis', 'nombre_original' => 'Tuberculosis', 'uri_linealizacion' => 'uri:2'],
        ]);
        $this->withoutMiddleware();
        $this->getJson('/api/v1/diagnosticos?buscar=1A00')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', '1A00');
        $this->getJson('/api/v1/diagnosticos?buscar=Tuberc')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', '1B00');
        $this->getJson('/api/v1/diagnosticos?buscar=colera')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', '1A00');
        $this->getJson('/api/v1/diagnosticos?buscar=CÓLERA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', '1A00');
        $this->getJson('/api/v1/diagnosticos?buscar=1a00')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', '1A00');
        $this->getJson('/api/v1/diagnosticos?buscar=x')->assertUnprocessable();
    }
}
