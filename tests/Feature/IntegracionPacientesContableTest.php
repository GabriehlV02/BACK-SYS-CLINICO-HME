<?php

namespace Tests\Feature;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegracionPacientesContableTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambos_accesos_comparten_registro_y_validacion_de_ci(): void
    {
        config(['services.contable.token' => str_repeat('x', 48)]);
        $datos = ['nombres' => 'Prueba', 'apellido_paterno' => 'Compartido', 'apellido_materno' => 'Paciente',
            'ci' => 'COMP-123', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino',
            'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro',
            'responsable_nombre_completo' => 'Responsable prueba', 'responsable_parentesco' => 'Madre'];
        $id = $this->withToken(str_repeat('x', 48))->postJson('/api/integraciones/contable/pacientes', $datos)->assertCreated()->json('data.id');
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->getJson('/api/v1/pacientes?buscar=COMP-123')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('total', 1);
        $this->postJson('/api/v1/pacientes', $datos)->assertUnprocessable()->assertJsonValidationErrors('ci');
        $this->postJson('/api/v1/pacientes', [...$datos, 'ci' => 'CLIN-456'])->assertCreated();
        $this->getJson('/api/integraciones/contable/pacientes?buscar=CLIN-456')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.ci', 'CLIN-456');
        $this->assertDatabaseCount('pacientes', 2);
        $this->assertDatabaseCount('registros_admision_paciente', 2);
        $this->assertDatabaseCount('paciente_responsables', 2);
    }

    public function test_rechaza_integracion_sin_credencial_o_con_credencial_invalida(): void
    {
        config(['services.contable.token' => str_repeat('x', 48)]);
        $this->getJson('/api/integraciones/contable/pacientes')->assertUnauthorized();
        $this->withToken('invalido')->postJson('/api/integraciones/contable/pacientes', [])->assertUnauthorized();
        config(['services.contable.token' => '']);
        $this->getJson('/api/integraciones/contable/pacientes')->assertUnauthorized();
        $this->assertDatabaseCount('pacientes', 0);
    }
}
