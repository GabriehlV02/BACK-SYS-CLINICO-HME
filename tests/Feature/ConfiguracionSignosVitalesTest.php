<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfiguracionSignosVitalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('configuracion_signos_vitales');
        (require database_path('migrations/2026_11_05_120915_create_configuracion_signos_vitales_table.php'))->up();
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => 'usuario-prueba']));
        $this->mock(AccesoCompartido::class)->shouldReceive('exigir')->with('usuario-prueba', 'SIGNOS_VITALES_REGISTRAR')->zeroOrMoreTimes();
    }

    public function test_registra_edita_y_desactiva_un_signo_con_rango(): void
    {
        $url = '/api/v1/configuracion/signos-vitales';
        $datos = ['nombre' => 'Signo de prueba', 'unidad' => 'Puntos', 'abreviatura' => 'pts', 'tipo' => 'Número decimal', 'rangoReferencia' => ['nombre' => 'Adultos', 'minimo' => 1.5, 'maximo' => 8.5]];
        $id = $this->postJson($url, $datos)->assertCreated()->assertJsonPath('data.rangoReferencia.minimo', 1.5)->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonFragment(['id' => $id, 'nombre' => 'Signo de prueba']);
        $datos['nombre'] = 'Signo actualizado';
        $this->putJson("$url/$id", $datos)->assertOk()->assertJsonPath('data.nombre', 'Signo actualizado');
        $this->deleteJson("$url/$id")->assertOk();
        $this->getJson($url)->assertJsonMissing(['id' => $id]);
        $this->assertDatabaseHas('configuracion_signos_vitales', ['id' => $id, 'activo' => false]);
    }

    public function test_rechaza_rangos_invalidos_y_nombres_duplicados(): void
    {
        $url = '/api/v1/configuracion/signos-vitales';
        $datos = ['nombre' => 'Temperatura', 'unidad' => 'Grados Celsius', 'abreviatura' => '°C', 'tipo' => 'Número entero'];
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $datos['nombre'] = 'Otro signo';
        $datos['rangoReferencia'] = ['nombre' => 'Rango', 'minimo' => 4, 'maximo' => 2];
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('rangoReferencia.maximo');
    }
}
