<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CitasPacientesTest extends TestCase
{
    use RefreshDatabase;

    public function test_medico_recibe_prioridades_solo_de_su_agenda(): void
    {
        $datos = $this->datos();
        $propia = $this->postJson('/api/citas', $datos)->assertCreated()->json('id');
        $ajena = $this->postJson('/api/citas', [...$datos, 'hora' => '09:00'])->assertCreated()->json('id');
        $usuario = '33333333-3333-4333-8333-333333333333';
        $profesional = '44444444-4444-4444-8444-444444444444';
        config(['database.connections.usuarios' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('usuarios');
        Schema::connection('usuarios')->create('profesionales', function (Blueprint $tabla): void {
            $tabla->uuid('id');
            $tabla->uuid('usuario_id');
            $tabla->boolean('activo');
            $tabla->string('tipo');
        });
        DB::connection('usuarios')->table('profesionales')->insert(['id' => $profesional, 'usuario_id' => $usuario, 'activo' => true, 'tipo' => 'Médico/a']);
        DB::table('citas')->where('id', $propia)->update(['profesional_id' => $profesional]);
        foreach ([$propia, $ajena] as $cita) {
            DB::table('valoraciones_signos_vitales')->insert(['cita_id' => $cita, 'registrado_por' => $usuario, 'created_at' => now(), 'mediciones' => json_encode([['id' => 'prueba', 'nombre' => 'Prueba', 'valor' => '30', 'unidad' => 'pts', 'alertaPrioritaria' => true]])]);
        }
        $this->withoutMiddleware(RegistrarAuditoriaAccion::class);
        $this->actingAs(new GenericUser(['id' => $usuario]));
        $this->mock(AccesoCompartido::class)->shouldReceive('esAdministrador')->andReturn(false);
        $this->getJson('/api/citas')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $propia)->assertJsonCount(1, '0.prioridad.mediciones');
    }

    public function test_agenda_publica_solo_la_prioridad_de_la_ultima_valoracion(): void
    {
        $datos = $this->datos();
        $cita = $this->postJson('/api/citas', $datos)->assertCreated()->json('id');
        $valores = ['cita_id' => $cita, 'registrado_por' => '33333333-3333-4333-8333-333333333333', 'created_at' => now()];
        $id = DB::table('valoraciones_signos_vitales')->insertGetId([...$valores, 'mediciones' => json_encode([['id' => 'prueba', 'nombre' => 'Prueba', 'valor' => '30', 'unidad' => 'pts', 'alertaPrioritaria' => true]])]);
        $this->getJson('/api/citas')->assertOk()->assertJsonPath('0.prioridad.id', (string) $id)->assertJsonPath('0.prioridad.mediciones.0.valor', '30');
        DB::table('valoraciones_signos_vitales')->insert([...$valores, 'mediciones' => json_encode([['id' => 'prueba', 'nombre' => 'Prueba', 'valor' => '15', 'alertaPrioritaria' => false]])]);
        $this->getJson('/api/citas')->assertOk()->assertJsonPath('0.prioridad', null);
    }

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

    public function test_reconsulta_se_vincula_a_la_cita_original(): void
    {
        $datos = $this->datos();
        $origen = $this->postJson('/api/citas', $datos)->assertCreated()->json('id');
        $reconsulta = $this->postJson('/api/citas', [...$datos, 'fecha' => '2026-11-02', 'estado' => 'por_confirmar', 'tipo' => 'reconsulta', 'cita_origen_id' => $origen])
            ->assertCreated()->assertJsonPath('tipo', 'reconsulta')->assertJsonPath('citaOrigenId', (int) $origen)->json('id');
        $this->assertDatabaseHas('citas', ['id' => $reconsulta, 'cita_origen_id' => $origen, 'tipo' => 'reconsulta', 'paciente_id' => $datos['paciente_id']]);
        $this->postJson('/api/citas', [...$datos, 'fecha' => '2026-10-31', 'tipo' => 'reconsulta', 'cita_origen_id' => $origen])->assertUnprocessable()->assertJsonValidationErrors('cita_origen_id');
        $this->postJson('/api/citas', [...$datos, 'fecha' => '2026-11-03', 'tipo' => 'reconsulta'])->assertUnprocessable()->assertJsonValidationErrors('cita_origen_id');
    }
}
