<?php

namespace Tests\Feature;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitasPacientesTest extends TestCase
{
    use RefreshDatabase;

    private function datos(): array
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $paciente = $this->postJson('/api/v1/pacientes', ['nombres' => 'Prueba', 'apellido_paterno' => 'Cita', 'apellido_materno' => 'Clinica', 'ci' => 'CITA-123', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'])->assertCreated()->json('data.id');

        return ['paciente_id' => $paciente, 'doctor' => 'Profesional prueba', 'especialidad' => 'Medicina general', 'consultorio' => 'Consultorio prueba', 'fecha' => '2026-11-01', 'hora' => '08:00', 'estado' => 'confirmada'];
    }

    public function test_cita_conserva_la_referencia_y_lee_la_identidad_del_paciente(): void
    {
        $datos = $this->datos();
        $id = $this->postJson('/api/citas', [...$datos, 'paciente' => 'Nombre manipulado'])->assertCreated()->assertJsonPath('paciente', 'Prueba Cita Clinica')->json('id');
        $this->getJson('/api/citas')->assertOk()->assertJsonPath('0.pacienteId', $datos['paciente_id']);
        $this->assertDatabaseHas('citas', ['id' => $id, 'paciente_id' => $datos['paciente_id']]);
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_horario_ocupado_se_libera_al_cancelar(): void
    {
        $datos = $this->datos();
        $id = $this->postJson('/api/citas', $datos)->assertCreated()->json('id');
        $this->postJson('/api/citas', $datos)->assertUnprocessable()->assertJsonValidationErrors('hora');
        $this->patchJson('/api/citas/'.$id.'/estado', ['estado' => 'cancelada'])->assertOk();
        $this->postJson('/api/citas', $datos)->assertCreated();
        $this->assertDatabaseCount('citas', 2);
    }

    public function test_no_admite_paciente_inexistente(): void
    {
        $datos = $this->datos();
        $this->postJson('/api/citas', [...$datos, 'paciente_id' => '00000000-0000-4000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('paciente_id');
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_citas_requieren_sesion(): void
    {
        $this->getJson('/api/citas')->assertUnauthorized();
        $this->postJson('/api/citas', [])->assertUnauthorized();
    }
}
