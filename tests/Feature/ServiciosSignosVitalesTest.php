<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiciosSignosVitalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('servicio_signo_vital');
        Schema::dropIfExists('servicios_atencion_signos');
        Schema::dropIfExists('configuracion_signos_vitales');
        (require database_path('migrations/2026_11_05_120915_create_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_06_142231_create_servicios_signos_vitales_tables.php'))->up();
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => 'usuario-prueba']));
        $this->mock(AccesoCompartido::class)->shouldReceive('exigir')->with('usuario-prueba', 'SIGNOS_VITALES_REGISTRAR')->zeroOrMoreTimes();
    }

    public function test_edita_asignaciones_independientes_y_admite_lista_vacia(): void
    {
        $url = '/api/v1/configuracion/servicios-signos-vitales';
        $this->getJson($url)->assertOk()->assertJsonCount(5, 'data')->assertJsonFragment(['id' => 'consulta-ambulatoria']);
        $this->putJson("$url/consulta-ambulatoria", ['signos' => ['temperatura', 'peso']])->assertOk();
        $this->assertDatabaseHas('servicio_signo_vital', ['servicio_id' => 'consulta-ambulatoria', 'signo_id' => 'temperatura']);
        $this->assertDatabaseMissing('servicio_signo_vital', ['servicio_id' => 'consulta-ambulatoria', 'signo_id' => 'glicemia']);
        $this->assertDatabaseHas('servicio_signo_vital', ['servicio_id' => 'emergencias', 'signo_id' => 'glicemia']);
        $this->putJson("$url/consulta-ambulatoria", ['signos' => []])->assertOk();
        $this->assertSame(0, DB::table('servicio_signo_vital')->where('servicio_id', 'consulta-ambulatoria')->count());
    }

    public function test_rechaza_signos_invalidos_y_servicios_inexistentes_sin_modificar_asignaciones(): void
    {
        $url = '/api/v1/configuracion/servicios-signos-vitales';
        DB::table('configuracion_signos_vitales')->where('id', 'peso')->update(['activo' => false]);
        $this->putJson("$url/emergencias", ['signos' => ['peso']])->assertUnprocessable();
        $this->putJson("$url/emergencias", ['signos' => ['temperatura', 'temperatura']])->assertUnprocessable();
        $this->putJson("$url/inexistente", ['signos' => []])->assertNotFound();
        $this->assertDatabaseHas('servicio_signo_vital', ['servicio_id' => 'emergencias', 'signo_id' => 'glicemia']);
    }
}
