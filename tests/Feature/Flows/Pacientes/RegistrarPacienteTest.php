<?php

namespace Tests\Feature\Flows\Pacientes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarPacienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_un_paciente(): void
    {
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
