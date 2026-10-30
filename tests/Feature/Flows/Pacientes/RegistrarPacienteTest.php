<?php

namespace Tests\Feature\Flows\Pacientes;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarPacienteTest extends TestCase
{
    use RefreshDatabase;

    private function datosPaciente(): array
    {
        return [
            'nombres' => 'Paciente Prueba', 'apellido_paterno' => 'Registro', 'apellido_materno' => 'Clinico',
            'ci' => 'TEST-2026', 'fecha_nacimiento' => '1990-05-12', 'genero' => 'Femenino',
            'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro',
        ];
    }

    public function test_registro_completo_persiste_y_aparece_en_el_listado(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $datos = [...$this->datosPaciente(), 'ci_con_qr' => true, 'complemento_ci' => '1A',
            'expedido_en' => 'CBBA - COCHABAMBA', 'nit' => '123456789', 'razon_social' => 'Prueba',
            'telefono' => '70000000', 'ciudad' => 'Cochabamba', 'zona_barrio' => 'Centro',
            'direccion_domicilio' => 'Calle de prueba', 'observaciones' => 'Registro de prueba', 'habilitado' => false,
            'responsable_nombre_completo' => 'Responsable Prueba', 'responsable_ci' => 'TEST-RESP',
            'responsable_telefono' => '70000001', 'responsable_parentesco' => 'Madre'];
        $id = $this->postJson('/api/v1/pacientes', $datos)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('pacientes', ['id' => $id, 'nit' => '123456789', 'ci_con_qr' => true,
            'complemento_ci' => '1A', 'direccion_domicilio' => 'Calle de prueba']);
        $this->assertDatabaseHas('registros_admision_paciente', ['paciente_id' => $id, 'habilitado' => false, 'observaciones' => 'Registro de prueba']);
        $this->assertDatabaseHas('paciente_responsables', ['paciente_id' => $id, 'ci' => 'TEST-RESP', 'nombre_completo' => 'Responsable Prueba']);
        $this->getJson('/api/v1/pacientes')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
    }

    public function test_ci_duplicado_no_crea_otro_paciente_ni_admision(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->postJson('/api/v1/pacientes', $this->datosPaciente())->assertCreated();
        $this->postJson('/api/v1/pacientes', $this->datosPaciente())->assertUnprocessable()
            ->assertJsonValidationErrors('ci')->assertJsonPath('errors.ci.0', 'Ya existe un paciente registrado con ese CI.');
        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('registros_admision_paciente', 1);
    }

    public function test_no_descarta_datos_del_responsable_sin_nombre(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->postJson('/api/v1/pacientes', [...$this->datosPaciente(), 'responsable_ci' => 'RESP-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('responsable_nombre_completo');
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_fecha_futura_es_rechazada(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $this->postJson('/api/v1/pacientes', [...$this->datosPaciente(), 'fecha_nacimiento' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_listado_y_registro_requieren_sesion(): void
    {
        $this->getJson('/api/v1/pacientes')->assertUnauthorized();
        $this->postJson('/api/v1/pacientes', $this->datosPaciente())->assertUnauthorized();
    }

    public function test_registra_un_paciente(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $response = $this->postJson('/api/v1/pacientes', [
            'nombres' => 'Ana', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'López',
            'ci' => '1234567', 'fecha_nacimiento' => '1990-05-12', 'genero' => 'FEMENINO',
            'pais' => 'Bolivia', 'departamento' => 'Cochabamba',
            'como_se_entero' => 'Recomendación', 'observaciones' => 'Registro inicial',
        ]);

        $response->assertCreated()->assertJsonPath('data.ci', '1234567');
        $this->assertDatabaseHas('pacientes', ['ci' => '1234567']);
        $this->assertDatabaseHas('registros_admision_paciente', ['como_se_entero' => 'Recomendación']);
    }
}
