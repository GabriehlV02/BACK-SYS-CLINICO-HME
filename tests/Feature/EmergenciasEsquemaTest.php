<?php

namespace Tests\Feature;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmergenciasEsquemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inicia_sin_identificacion_y_conserva_la_fecha_de_ingreso(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);

        $respuesta = $this->postJson('/api/emergencias', [
            'cubiculo' => '2',
            'inicio' => '2026-10-29T15:35:00.000Z',
        ])->assertCreated()->assertJsonPath('identidad', null);

        $atencion = DB::table('emergencias')->where('id', $respuesta->json('id'))->value('atencion_id');
        $this->assertDatabaseHas('atenciones', [
            'id' => $atencion,
            'paciente_id' => null,
            'iniciada_en' => '2026-10-29 15:35:00',
        ]);
        $this->postJson('/api/emergencias', ['cubiculo' => '3', 'inicio' => 'fecha inválida'])->assertUnprocessable();
    }

    public function test_registra_evalua_y_cierra_emergencia_sin_tablas_anticipadas(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertCreated()->json('id');
        $this->assertDatabaseHas('emergencias', ['id' => $id, 'ubicacion_activa' => '1']);
        $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertUnprocessable();
        $this->postJson("/api/emergencias/{$id}/evaluacion", ['revision' => 1, 'campos' => ['motivo' => 'Control']])
            ->assertOk()->assertJsonPath('evaluaciones.0.campos.motivo', 'Control');
        $this->postJson("/api/emergencias/{$id}/cierre", ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])
            ->assertOk()->assertJsonPath('cierre.causaEgreso', 'hospitalaria');
        $atencion = DB::table('emergencias')->where('id', $id)->value('atencion_id');
        $this->assertDatabaseHas('atenciones', ['id' => $atencion, 'estado' => 'finalizada']);
        $this->assertDatabaseHas('emergencias', ['id' => $id, 'ubicacion_activa' => null]);
        $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertCreated();
    }

    public function test_signos_de_emergencia_respetan_la_configuracion_del_servicio(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '4'])->assertCreated()->json('id');
        DB::table('servicio_signo_vital')->where('servicio_id', 'emergencias')->delete();
        DB::table('servicio_signo_vital')->insert([
            'servicio_id' => 'emergencias',
            'signo_id' => 'temperatura',
        ]);

        $this->postJson("/api/emergencias/{$id}/signos", [
            'valores' => ['temperatura' => '36.7'],
        ])->assertOk()
            ->assertJsonPath('signos.0.valores.temperatura', '36.7')
            ->assertJsonPath('signos.0.mediciones.0.nombre', 'Temperatura');
        $this->postJson("/api/emergencias/{$id}/signos", [
            'valores' => ['peso' => '70'],
        ])->assertUnprocessable();
    }
}
