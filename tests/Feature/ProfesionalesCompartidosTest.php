<?php

namespace Tests\Feature;

use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProfesionalesCompartidosTest extends TestCase
{
    use RefreshDatabase;

    private function catalogo(): string
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        config(['database.connections.usuarios' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
        DB::purge('usuarios');
        Schema::connection('usuarios')->create('profesionales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('usuario_id')->nullable();
            $t->string('nombres');
            $t->string('apellidos');
            $t->string('tipo');
            $t->text('especialidades');
            $t->string('rol');
            $t->string('matricula');
            $t->boolean('activo');
        });
        $id = '00000000-0000-4000-8000-000000000001';
        DB::connection('usuarios')->table('profesionales')->insert(['id' => $id, 'nombres' => 'Médico', 'apellidos' => 'Compartido', 'tipo' => 'Médico/a', 'especialidades' => '["Medicina general"]', 'rol' => 'Tratante', 'matricula' => 'MED-TEST', 'activo' => true]);

        return $id;
    }

    public function test_catalogo_lee_el_contable_sin_duplicar_la_tabla_en_clinico(): void
    {
        $id = $this->catalogo();
        $this->getJson('/api/v1/profesionales')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.especialidades.0', 'Medicina general');
        $this->assertFalse(Schema::hasTable('profesionales'));
        DB::connection('usuarios')->table('profesionales')->where('id', $id)->update(['activo' => false]);
        $this->getJson('/api/v1/profesionales')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cita_guarda_el_id_y_el_nombre_del_profesional_verificado(): void
    {
        $id = $this->catalogo();
        $paciente = $this->postJson('/api/v1/pacientes', ['nombres' => 'Paciente', 'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Clinico', 'ci' => 'PROF-TEST', 'fecha_nacimiento' => '1990-01-01', 'genero' => 'Femenino', 'pais' => 'Bolivia', 'departamento' => 'Cochabamba', 'como_se_entero' => 'Otro'])->assertCreated()->json('data.id');
        $datos = ['paciente_id' => $paciente, 'profesional_id' => $id, 'doctor' => 'Nombre manipulado', 'especialidad' => 'Medicina general', 'consultorio' => 'Prueba', 'fecha' => '2026-11-01', 'hora' => '08:00', 'estado' => 'confirmada'];
        $cita = $this->postJson('/api/citas', $datos)->assertCreated()->assertJsonPath('doctor', 'Médico Compartido')->json('id');
        $this->assertDatabaseHas('citas', ['id' => $cita, 'profesional_id' => $id]);
        DB::connection('usuarios')->table('profesionales')->where('id', $id)->update(['activo' => false]);
        $this->postJson('/api/citas', [...$datos, 'hora' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('profesional_id');
        $this->patchJson('/api/citas/'.$cita.'/estado', ['estado' => 'confirmada'])->assertUnprocessable()->assertJsonValidationErrors('profesional_id');
    }

    public function test_estancia_asigna_profesional_sin_exigir_cuenta_de_acceso(): void
    {
        $id = $this->catalogo();
        $emergencia = $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertCreated()->json('id');
        $atencion = DB::table('emergencias')->where('id', $emergencia)->value('atencion_id');
        $this->postJson('/api/v1/atenciones/'.$atencion.'/estancias', ['area' => 'INTERNACION', 'profesionales' => [['profesional_id' => $id, 'rol' => 'RESPONSABLE']]])
            ->assertCreated()->assertJsonPath('data.profesionales.0.profesional_id', $id)->assertJsonPath('data.profesionales.0.usuario_id', null);
        $this->assertDatabaseHas('estancia_profesionales', ['profesional_id' => $id]);
    }

    public function test_catalogo_requiere_sesion(): void
    {
        $this->getJson('/api/v1/profesionales')->assertUnauthorized();
    }
}
