<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnidadesMedidaSignosVitalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('unidades_medida_signos_vitales');
        Schema::dropIfExists('configuracion_signos_vitales');
        (require database_path('migrations/2026_11_05_120915_create_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_05_210856_create_unidades_medida_signos_vitales_table.php'))->up();
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => 'usuario-prueba']));
        $this->mock(AccesoCompartido::class)->shouldReceive('exigir')->with('usuario-prueba', 'SIGNOS_VITALES_REGISTRAR')->zeroOrMoreTimes();
    }

    public function test_lista_las_unidades_existentes_y_registra_una_nueva(): void
    {
        $url = '/api/v1/configuracion/unidades-signos-vitales';
        $this->getJson($url)->assertOk()->assertJsonFragment(['nombre' => 'Grados Celsius', 'abreviatura' => '°C']);
        $this->postJson($url, ['nombre' => '  Unidad de prueba  ', 'abreviatura' => '  u/p  '])->assertCreated()->assertJsonPath('data.nombre', 'Unidad de prueba')->assertJsonPath('data.abreviatura', 'u/p');
        $this->getJson($url)->assertJsonFragment(['nombre' => 'Unidad de prueba', 'abreviatura' => 'u/p']);
        $this->assertDatabaseHas('unidades_medida_signos_vitales', ['nombre' => 'Unidad de prueba', 'abreviatura' => 'u/p']);
    }

    public function test_rechaza_unidades_duplicadas_y_datos_incompletos(): void
    {
        $url = '/api/v1/configuracion/unidades-signos-vitales';
        $this->postJson($url, ['nombre' => 'grados celsius', 'abreviatura' => '°C'])->assertUnprocessable();
        $this->postJson($url, ['nombre' => 'Nueva', 'abreviatura' => ' '])->assertUnprocessable()->assertJsonValidationErrors('abreviatura');
    }
}
