<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtencionConsultaTest extends TestCase
{
    use RefreshDatabase;

    public function test_atencion_exige_sesion(): void
    {
        $this->getJson('/api/v1/citas/1/atencion')->assertUnauthorized();
        $this->putJson('/api/v1/citas/1/atencion', [])->assertUnauthorized();
        $this->getJson('/api/v1/citas/1/historial')->assertUnauthorized();
    }

    public function test_guarda_atencion_de_la_cita_y_protege_contra_cambios_concurrentes(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->actingAs(new GenericUser(['id' => '00000000-0000-4000-8000-000000000001']));
        $this->mock(AccesoCompartido::class, fn ($mock) => $mock->shouldReceive('esAdministrador')->andReturnTrue());
        $paciente = $this->postJson('/api/v1/pacientes', ['nombres' => 'Prueba', 'apellido_paterno' => 'Cita', 'apellido_materno' => 'Clinica', 'ci' => 'ATEST-123', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'])->assertCreated()->json('data.id');
        $cita = $this->postJson('/api/citas', ['paciente_id' => $paciente, 'doctor' => 'Profesional prueba', 'especialidad' => 'General', 'consultorio' => '1', 'fecha' => '2026-11-13', 'hora' => '10:00', 'estado' => 'confirmada'])->assertCreated()->json('id');
        $url = '/api/v1/citas/'.$cita.'/atencion';
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
        $contenido = ['motivo' => 'Control', 'enfermedadActual' => 'Evolución', 'examenFisico' => '', 'antecedentes' => '', 'notas' => '', 'diagnosticos' => [['diagnostico' => '1A00 · CÓLERA', 'planes' => ['Hidratación']]], 'revision' => ['general' => 'Observación'], 'ordenes' => ['Hemograma']];
        $this->putJson($url, ['version' => null, 'finalizar' => false, 'contenido' => $contenido])->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.contenido.revision.general', 'Observación');
        $this->putJson($url, ['version' => null, 'finalizar' => true, 'contenido' => $contenido])->assertStatus(409);
        $this->putJson($url, ['version' => 1, 'finalizar' => true, 'contenido' => $contenido])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($url)->assertOk()->assertJsonPath('data.contenido.diagnosticos.0.planes.0', 'Hidratación');
        $this->putJson($url, ['version' => 2, 'finalizar' => false, 'contenido' => $contenido])->assertStatus(409);
        $this->getJson('/api/citas')->assertOk()->assertJsonPath('0.id', (string) $cita)->assertJsonPath('0.atencionFinalizadaEn', fn ($value) => $value !== null);
    }

    public function test_historial_muestra_solo_atenciones_finalizadas_anteriores_del_mismo_paciente(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->actingAs(new GenericUser(['id' => '00000000-0000-4000-8000-000000000001']));
        $this->mock(AccesoCompartido::class, fn ($mock) => $mock->shouldReceive('esAdministrador')->andReturnTrue());
        $datos = ['nombres' => 'Prueba', 'apellido_paterno' => 'Cita', 'apellido_materno' => 'Clinica', 'ci' => 'HIST-1', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'];
        $paciente = $this->postJson('/api/v1/pacientes', $datos)->assertCreated()->json('data.id');
        $otro = $this->postJson('/api/v1/pacientes', [...$datos, 'ci' => 'HIST-2'])->assertCreated()->json('data.id');
        $cita = ['paciente_id' => $paciente, 'doctor' => 'Médico', 'especialidad' => 'General', 'consultorio' => '1', 'fecha' => '2026-11-10', 'hora' => '10:00', 'estado' => 'confirmada'];
        $anterior = $this->postJson('/api/citas', $cita)->assertCreated()->json('id');
        $actual = $this->postJson('/api/citas', [...$cita, 'fecha' => '2026-11-13'])->assertCreated()->json('id');
        $ajeno = $this->postJson('/api/citas', [...$cita, 'paciente_id' => $otro, 'fecha' => '2026-11-09'])->assertCreated()->json('id');
        $futuro = $this->postJson('/api/citas', [...$cita, 'fecha' => '2026-11-14'])->assertCreated()->json('id');
        $borrador = $this->postJson('/api/citas', [...$cita, 'fecha' => '2026-11-11'])->assertCreated()->json('id');
        $contenido = ['motivo' => 'Control', 'enfermedadActual' => '', 'examenFisico' => '', 'antecedentes' => '', 'notas' => '', 'diagnosticos' => [], 'revision' => [], 'ordenes' => []];
        foreach ([$anterior, $ajeno, $futuro] as $id) $this->putJson('/api/v1/citas/'.$id.'/atencion', ['version' => null, 'finalizar' => true, 'contenido' => $contenido])->assertOk();
        $this->putJson('/api/v1/citas/'.$borrador.'/atencion', ['version' => null, 'finalizar' => false, 'contenido' => $contenido])->assertOk();
        \Illuminate\Support\Facades\DB::table('valoraciones_signos_vitales')->insert(['cita_id' => $anterior, 'registrado_por' => '00000000-0000-4000-8000-000000000001', 'mediciones' => json_encode([['nombre' => 'Temperatura', 'valor' => '37', 'unidad' => '°C']]), 'created_at' => now()]);
        $this->getJson('/api/v1/citas/'.$actual.'/historial')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.citaId', (string) $anterior)->assertJsonPath('data.0.contenido.motivo', 'Control')->assertJsonPath('data.0.signosVitales.0.valor', '37');
    }

    public function test_historial_no_se_expone_a_usuarios_sin_permiso(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->actingAs(new GenericUser(['id' => '00000000-0000-4000-8000-000000000001']));
        $paciente = $this->postJson('/api/v1/pacientes', ['nombres' => 'Prueba', 'apellido_paterno' => 'Cita', 'apellido_materno' => 'Clinica', 'ci' => 'HIST-3', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'])->assertCreated()->json('data.id');
        $cita = $this->postJson('/api/citas', ['paciente_id' => $paciente, 'doctor' => 'Médico', 'especialidad' => 'General', 'consultorio' => '1', 'fecha' => '2026-11-13', 'hora' => '10:00', 'estado' => 'confirmada'])->assertCreated()->json('id');
        $this->mock(AccesoCompartido::class, function ($mock): void {
            $mock->shouldReceive('esAdministrador')->andReturnFalse();
            $mock->shouldReceive('permisos')->andReturn([]);
        });
        $this->getJson('/api/v1/citas/'.$cita.'/historial')->assertForbidden();
    }
}
