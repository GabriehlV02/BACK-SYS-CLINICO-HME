<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VinculosPacientesTest extends TestCase
{
    use RefreshDatabase;

    private function paciente(string $ci = 'VINC-123'): array
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);

        return $this->postJson('/api/v1/pacientes', ['nombres' => 'Prueba', 'apellido_paterno' => 'Vinculo', 'apellido_materno' => 'Clinico', 'ci' => $ci,
            'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'])
            ->assertCreated()->json('data');
    }

    public function test_edita_la_misma_ficha_y_el_contable_recibe_el_cambio(): void
    {
        $paciente = $this->paciente();
        $this->putJson('/api/v1/pacientes/'.$paciente['id'], [...$paciente, 'telefono' => '70000001', 'fecha_nacimiento' => '1990-01-01'])
            ->assertOk()->assertJsonPath('data.id', $paciente['id']);
        config(['services.contable.token' => str_repeat('x', 48)]);
        $this->withToken(str_repeat('x', 48))->getJson('/api/integraciones/contable/pacientes?paciente_id='.$paciente['id'])
            ->assertOk()->assertJsonPath('data.0.telefono', '70000001');
        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('registros_admision_paciente', 1);
    }

    public function test_edicion_rechaza_ci_de_otro_paciente_y_fecha_futura(): void
    {
        $primero = $this->paciente();
        $segundo = $this->paciente('OTRO-123');
        $this->putJson('/api/v1/pacientes/'.$segundo['id'], [...$segundo, 'ci' => $primero['ci'], 'fecha_nacimiento' => '1990-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('ci');
        $this->putJson('/api/v1/pacientes/'.$segundo['id'], [...$segundo, 'fecha_nacimiento' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
        $this->assertDatabaseHas('pacientes', ['id' => $segundo['id'], 'ci' => 'OTRO-123']);
    }

    public function test_emergencia_usa_el_id_del_paciente_y_permite_identidad_provisional(): void
    {
        $paciente = $this->paciente();
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '1', 'identidad' => ['documento' => 'VINC-123']])
            ->assertCreated()->assertJsonPath('pacienteId', $paciente['id'])->json('id');
        $atencion = DB::table('emergencias')->where('id', $id)->value('atencion_id');
        $this->assertDatabaseHas('atenciones', ['id' => $atencion, 'paciente_id' => $paciente['id']]);
        $provisional = $this->postJson('/api/emergencias', ['cubiculo' => '2'])->assertCreated()->assertJsonPath('pacienteId', null)->json('id');
        $this->postJson('/api/emergencias/'.$id.'/cierre', ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])->assertOk();
        $this->postJson('/api/emergencias/'.$provisional.'/identidad', ['revision' => 1, 'documento' => $paciente['ci']])
            ->assertOk()->assertJsonPath('pacienteId', $paciente['id']);
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_no_permite_consultar_o_editar_ficha_sin_sesion(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';
        $this->getJson('/api/v1/pacientes/'.$id)->assertUnauthorized();
        $this->putJson('/api/v1/pacientes/'.$id, [])->assertUnauthorized();
    }

    public function test_registro_completo_vincula_una_emergencia_provisional_del_mismo_ci(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '1', 'identidad' => ['documento' => 'VINC-123']])->assertCreated()->assertJsonPath('pacienteId', null)->json('id');
        $paciente = $this->paciente();
        $this->getJson('/api/emergencias')->assertOk()->assertJsonPath('cuentas.0.pacienteId', $paciente['id']);
        $atencion = DB::table('emergencias')->where('id', $id)->value('atencion_id');
        $this->assertDatabaseHas('atenciones', ['id' => $atencion, 'paciente_id' => $paciente['id']]);
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_agenda_de_estudios_muestra_la_identidad_del_paciente_registrado(): void
    {
        $paciente = $this->paciente();
        $this->postJson('/api/v1/pacientes/'.$paciente['id'].'/estudios', ['tipo' => 'TOMOGRAFIA', 'nombre' => 'Estudio de prueba'])->assertCreated();
        $this->getJson('/api/v1/estudios/agenda')->assertOk()->assertJsonPath('data.0.paciente_id', $paciente['id'])->assertJsonPath('data.0.ci', 'VINC-123');
    }

    public function test_vincula_el_paciente_al_cliente_contable_con_permiso_y_credencial_interna(): void
    {
        $paciente = $this->paciente();
        $usuarioId = '00000000-0000-4000-8000-000000000001';
        $this->actingAs(new GenericUser(['id' => $usuarioId]));
        $this->mock(AccesoCompartido::class)->shouldReceive('exigir')->once()->with($usuarioId, 'VENTAS_REGISTRAR');
        config(['services.contable.url' => 'http://contable.test', 'services.contable.token' => str_repeat('x', 48)]);
        Http::preventStrayRequests();
        Http::fake(['contable.test/api/integraciones/clinico/clientes' => Http::response(['data' => ['id' => 'cliente-compartido', 'paciente_id' => $paciente['id']]], 201)]);
        $this->postJson('/api/v1/pacientes/'.$paciente['id'].'/cliente-contable', ['nombres' => 'Manipulado'])
            ->assertCreated()->assertJsonPath('data.paciente_id', $paciente['id']);
        Http::assertSent(fn ($request) => $request['id'] === $paciente['id'] && $request['nombres'] === 'Prueba' && $request->hasHeader('Authorization', 'Bearer '.str_repeat('x', 48)));
    }
}
