<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ValoracionesSignosVitalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('valoraciones_signos_vitales');
        Schema::dropIfExists('servicio_signo_vital');
        Schema::dropIfExists('servicios_atencion_signos');
        Schema::dropIfExists('configuracion_signos_vitales');
        Schema::dropIfExists('citas');
        Schema::create('citas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('paciente_id');
            $table->uuid('profesional_id')->nullable();
            $table->string('estado');
        });
        (require database_path('migrations/2026_11_05_120915_create_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_05_212049_add_componentes_to_configuracion_signos_vitales_table.php'))->up();
        (require database_path('migrations/2026_11_06_142231_create_servicios_signos_vitales_tables.php'))->up();
        (require database_path('migrations/2026_11_06_191143_create_valoraciones_signos_vitales_table.php'))->up();
        DB::table('citas')->insert([
            ['id' => 1, 'paciente_id' => '11111111-1111-4111-8111-111111111111', 'estado' => 'confirmada'],
            ['id' => 2, 'paciente_id' => '11111111-1111-4111-8111-111111111111', 'estado' => 'confirmada'],
            ['id' => 3, 'paciente_id' => '22222222-2222-4222-8222-222222222222', 'estado' => 'cancelada'],
        ]);
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => '33333333-3333-4333-8333-333333333333']));
    }

    public function test_guarda_versiones_por_cita_y_conserva_mediciones_anteriores(): void
    {
        $this->mock(AccesoCompartido::class)->shouldReceive('esAdministrador')->andReturn(true);
        $url = '/api/v1/citas/1/signos-vitales';
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
        $version = $this->postJson($url, ['version' => null, 'valores' => ['temperatura' => '36.7']])->assertCreated()->assertJsonPath('data.valores.temperatura', '36.7')->assertJsonPath('data.mediciones.0.unidad', '°C')->json('data.id');
        $this->postJson($url, ['version' => null, 'valores' => ['temperatura' => '38']])->assertStatus(409);
        $this->postJson($url, ['version' => $version, 'valores' => ['temperatura' => '37']])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.valores.temperatura', '37');
        $this->getJson('/api/v1/citas/2/signos-vitales')->assertJsonPath('data', null);
        $this->assertSame(2, DB::table('valoraciones_signos_vitales')->where('cita_id', 1)->count());
    }

    public function test_rechaza_mediciones_invalidas_y_citas_canceladas(): void
    {
        $this->mock(AccesoCompartido::class)->shouldReceive('esAdministrador')->andReturn(true);
        $url = '/api/v1/citas/1/signos-vitales';
        $this->postJson($url, ['version' => null, 'valores' => ['inexistente' => '20']])->assertUnprocessable();
        $this->postJson($url, ['version' => null, 'valores' => ['frecuenciaCardiaca' => '1.5']])->assertUnprocessable();
        $this->postJson($url, ['version' => null, 'valores' => []])->assertUnprocessable();
        $this->postJson('/api/v1/citas/3/signos-vitales', ['version' => null, 'valores' => ['temperatura' => '36']])->assertUnprocessable();
        $this->assertDatabaseCount('valoraciones_signos_vitales', 0);
    }

    public function test_rechaza_acceso_a_usuario_sin_permisos(): void
    {
        $mock = $this->mock(AccesoCompartido::class);
        $mock->shouldReceive('esAdministrador')->andReturn(false);
        $mock->shouldReceive('permisos')->andReturn([]);
        $this->getJson('/api/v1/citas/1/signos-vitales')->assertForbidden();
        $this->postJson('/api/v1/citas/1/signos-vitales', ['valores' => ['temperatura' => '36']])->assertForbidden();
    }

    public function test_historial_por_paciente_agrega_consultas_y_emergencias_sin_mezclar_pacientes(): void
    {
        Schema::dropIfExists('emergencias');
        Schema::dropIfExists('atenciones');
        Schema::create('atenciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paciente_id')->nullable();
            $table->string('tipo');
            $table->timestamp('iniciada_en')->nullable();
        });
        Schema::create('emergencias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('atencion_id');
            $table->string('codigo_emergencia');
            $table->text('datos_ingreso')->nullable();
        });
        DB::table('valoraciones_signos_vitales')->insert([
            'cita_id' => 1,
            'registrado_por' => '33333333-3333-4333-8333-333333333333',
            'mediciones' => json_encode([['id' => 'temperatura', 'nombre' => 'Temperatura', 'unidad' => '°C', 'valor' => '36.7']], JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 10:00:00',
        ]);
        DB::table('valoraciones_signos_vitales')->insert([
            'cita_id' => 3,
            'registrado_por' => '33333333-3333-4333-8333-333333333333',
            'mediciones' => json_encode([['id' => 'temperatura', 'valor' => '39']], JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 11:00:00',
        ]);
        DB::table('atenciones')->insert([
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'paciente_id' => '11111111-1111-4111-8111-111111111111',
            'tipo' => 'emergencia',
            'iniciada_en' => '2026-01-02 08:00:00',
        ]);
        DB::table('emergencias')->insert([
            'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'atencion_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'codigo_emergencia' => 'EMG-TEST',
            'datos_ingreso' => json_encode(['signos' => [[
                'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'fecha' => '2026-01-02T08:30:00+00:00',
                'valores' => ['saturacion' => 98],
                'observacion' => 'Control inicial',
            ]]], JSON_THROW_ON_ERROR),
        ]);

        $this->mock(AccesoCompartido::class)
            ->shouldReceive('exigir')
            ->once()
            ->with('33333333-3333-4333-8333-333333333333', 'SIGNOS_VITALES_REGISTRAR');

        $this->getJson('/api/v1/pacientes/11111111-1111-4111-8111-111111111111/signos-vitales')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.servicioId', 'consulta-ambulatoria')
            ->assertJsonPath('data.0.mediciones.0.valor', '36.7')
            ->assertJsonPath('data.1.servicioId', 'emergencias')
            ->assertJsonPath('data.1.contexto', 'Atención EMG-TEST')
            ->assertJsonPath('data.1.mediciones.0.id', 'saturacionAmbiente')
            ->assertJsonPath('data.1.mediciones.0.valor', '98');
    }

    public function test_medico_solo_accede_a_citas_de_su_perfil(): void
    {
        config(['database.connections.usuarios' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
        DB::purge('usuarios');
        Schema::connection('usuarios')->create('profesionales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->boolean('activo');
        });
        DB::connection('usuarios')->table('profesionales')->insert(['id' => '44444444-4444-4444-8444-444444444444', 'usuario_id' => '33333333-3333-4333-8333-333333333333', 'activo' => true]);
        DB::table('citas')->where('id', 1)->update(['profesional_id' => '44444444-4444-4444-8444-444444444444']);
        $mock = $this->mock(AccesoCompartido::class);
        $mock->shouldReceive('esAdministrador')->andReturn(false);
        $mock->shouldReceive('permisos')->andReturn(['CONSULTAS_ATENDER']);
        $this->postJson('/api/v1/citas/1/signos-vitales', ['version' => null, 'valores' => ['temperatura' => '36']])->assertCreated();
        $this->getJson('/api/v1/citas/2/signos-vitales')->assertForbidden();
        $this->postJson('/api/v1/citas/2/signos-vitales', ['version' => null, 'valores' => ['temperatura' => '36']])->assertForbidden();
    }
}
