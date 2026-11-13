<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfiguracionSignosVitalesTest extends TestCase
{
    public function test_alerta_es_opcional_y_exige_rangos_numericos(): void
    {
        $url = '/api/v1/configuracion/signos-vitales';
        $datos = ['nombre' => 'Medicion configurable', 'unidad' => 'Puntos', 'abreviatura' => 'pts', 'tipo' => 'Número decimal'];
        $id = $this->postJson($url, $datos)->assertCreated()->assertJsonPath('data.alertaPrioritaria', false)->json('data.id');
        $datos['alertaPrioritaria'] = true;
        $this->putJson("$url/$id", $datos)->assertUnprocessable()->assertJsonValidationErrors('alertaPrioritaria');
        $this->assertDatabaseHas('configuracion_signos_vitales', ['id' => $id, 'alerta_prioritaria' => false]);
        $datos['rangoReferencia'] = ['nombre' => 'Rango de prueba', 'minimo' => 10, 'maximo' => 20];
        $this->putJson("$url/$id", $datos)->assertOk()->assertJsonPath('data.alertaPrioritaria', true);
        $this->getJson($url)->assertJsonFragment(['id' => $id, 'alertaPrioritaria' => true]);
        $datos['tipo'] = 'Texto';
        $this->putJson("$url/$id", $datos)->assertUnprocessable();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('configuracion_signos_vitales');
        (require database_path('migrations/2026_11_05_120915_create_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_05_212049_add_componentes_to_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_13_144645_add_alerta_prioritaria_to_configuracion_signos_vitales_table.php'))->up();
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

    public function test_guarda_varios_componentes_con_sus_rangos(): void
    {
        $url = '/api/v1/configuracion/signos-vitales';
        $datos = ['nombre' => 'Presión', 'unidad' => 'Milímetros de mercurio', 'abreviatura' => 'mmHg', 'tipo' => 'Número decimal', 'componentes' => [
            ['id' => 'sistolica', 'nombre' => 'Sistólica', 'rangoReferencia' => ['nombre' => 'Sistólica', 'minimo' => 90, 'maximo' => 120]],
            ['id' => 'diastolica', 'nombre' => 'Diastólica'],
        ]];
        $id = $this->postJson($url, $datos)->assertCreated()->assertJsonPath('data.componentes.0.nombre', 'Sistólica')->assertJsonPath('data.componentes.0.rangoReferencia.maximo', 120)->json('data.id');
        $this->getJson($url)->assertJsonFragment(['id' => 'diastolica', 'nombre' => 'Diastólica']);
        $datos['componentes'][0]['rangoReferencia']['maximo'] = 80;
        $this->putJson("$url/$id", $datos)->assertUnprocessable();
    }

    public function test_referencias_orientativas_conservan_los_rangos_y_signos_modificados(): void
    {
        $personalizado = json_encode(['nombre' => 'Propio', 'minimo' => 1, 'maximo' => 2], JSON_THROW_ON_ERROR);
        DB::table('configuracion_signos_vitales')->where('id', 'temperatura')->update(['rango_referencia' => $personalizado]);
        DB::table('configuracion_signos_vitales')->where('id', 'presionSistolica')->update(['unidad' => 'Otra unidad']);
        (require database_path('migrations/2026_11_05_213800_agregar_referencias_orientativas_signos_vitales.php'))->up();
        $this->assertSame($personalizado, DB::table('configuracion_signos_vitales')->where('id', 'temperatura')->value('rango_referencia'));
        $this->assertNull(DB::table('configuracion_signos_vitales')->where('id', 'presionSistolica')->value('rango_referencia'));
        $rango = json_decode(DB::table('configuracion_signos_vitales')->where('id', 'frecuenciaCardiaca')->value('rango_referencia'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(60, $rango['minimo']);
        $this->assertSame(100, $rango['maximo']);
        $this->assertStringContainsString('validación clínica', $rango['contexto']);
        $this->assertNull(DB::table('configuracion_signos_vitales')->where('id', 'glicemia')->value('rango_referencia'));
    }
}
