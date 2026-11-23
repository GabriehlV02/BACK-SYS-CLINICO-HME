<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Farmacia\CatalogoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmergenciasEsquemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnosticos_por_emergencia_son_unicos_y_se_conservan_al_cerrar(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        DB::table('diagnosticos')->insert(['codigo' => 'QA', 'nombre' => 'Prueba', 'nombre_original' => 'Prueba', 'uri_linealizacion' => 'qa']);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '2'])->assertCreated()->json('id');
        $url = "/api/emergencias/{$id}/diagnosticos";
        $agregar = ['codigo' => 'QA', 'seleccionado' => true];
        $this->postJson($url, $agregar)->assertOk()->assertJsonCount(1);
        $this->postJson($url, $agregar)->assertOk()->assertJsonCount(1);
        $this->postJson($url, ['codigo' => 'QA', 'seleccionado' => false])->assertOk()->assertJsonCount(0);
        $this->postJson($url, $agregar)->assertOk();
        $this->postJson("/api/emergencias/{$id}/cierre", ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])->assertOk();
        $this->postJson($url, ['codigo' => 'QA', 'seleccionado' => false])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('0.codigo', 'QA');
    }

    public function test_documentacion_clinica_persiste_por_seccion_y_no_admite_cambios_tras_el_cierre(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '2'])->assertCreated()->json('id');
        $this->postJson("/api/emergencias/{$id}/documentacion", ['seccion' => 'anamnesis', 'datos' => ['relato' => 'Relato de prueba', 'impresion' => 'Dolor', 'fotos' => []]])->assertOk();
        $this->postJson("/api/emergencias/{$id}/documentacion", ['seccion' => 'revision', 'datos' => ['general' => '{"estados":["Fiebre"],"detalle":""}']])->assertOk();
        $this->getJson("/api/emergencias/{$id}/documentacion")->assertOk()->assertJsonPath('anamnesis.relato', 'Relato de prueba')->assertJsonPath('revision.general', '{"estados":["Fiebre"],"detalle":""}');
        $this->postJson("/api/emergencias/{$id}/documentacion", ['seccion' => 'anamnesis', 'datos' => ['relato' => 'x', 'fotos' => [['id' => '11111111-1111-4111-8111-111111111111', 'nombre' => 'x', 'url' => 'data:text/html;base64,PHNjcmlwdD4=']]]])->assertUnprocessable();
        $this->postJson("/api/emergencias/{$id}/cierre", ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])->assertOk();
        $this->postJson("/api/emergencias/{$id}/documentacion", ['seccion' => 'revision', 'datos' => ['general' => 'Cambio']])->assertUnprocessable();
    }

    public function test_evaluacion_identifica_al_profesional_y_su_rol_sin_aceptar_un_autor_enviado_por_el_cliente(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        Schema::create('roles_prueba', function (Blueprint $tabla): void {
            $tabla->string('codigo');
            $tabla->string('nombre');
        });
        $this->mock(AccesoCompartido::class)->shouldReceive('roles')->andReturn(DB::table('roles_prueba as r'));
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '7'])->assertCreated()->json('id');
        foreach ([['MEDICO_EMERGENCIAS', 'Medico', 'medico'], ['ENFERMERIA', 'Enfermeria', 'otro']] as $indice => [$codigo, $nombre, $tipo]) {
            DB::table('roles_prueba')->delete();
            DB::table('roles_prueba')->insert(['codigo' => $codigo, 'nombre' => $nombre]);
            $this->actingAs(new GenericUser(['id' => '11111111-1111-4111-8111-111111111111', 'nombres' => $nombre, 'apellidos' => 'Prueba']));
            $this->postJson("/api/emergencias/{$id}/evaluacion", ['revision' => 1, 'campos' => ['tratamiento' => 'Tratamiento de prueba'], 'autorTipo' => 'medico', 'usuario' => 'Autor manipulado'])
                ->assertOk()->assertJsonPath("evaluaciones.$indice.usuario", "$nombre Prueba")
                ->assertJsonPath("evaluaciones.$indice.autorTipo", $tipo)
                ->assertJsonPath("evaluaciones.$indice.autorId", '11111111-1111-4111-8111-111111111111');
        }
    }

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
            'iniciada_en' => Carbon::parse('2026-10-29T15:35:00.000Z')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]);
        $this->postJson('/api/emergencias', ['cubiculo' => '3', 'inicio' => 'fecha inválida'])->assertUnprocessable();
    }

    public function test_iniciar_atencion_persiste_y_aparece_en_el_listado(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $respuesta = $this->postJson('/api/emergencias', [
            'cubiculo' => '2',
            'inicio' => '2026-10-29T15:35:00.000Z',
            'identidad' => ['nombres' => 'Paciente', 'apellidos' => 'Prueba', 'documento' => '987654'],
            'solicitudId' => '11111111-1111-4111-8111-111111111111',
        ])->assertCreated()->assertJsonPath('cubiculo', '2');

        $id = $respuesta->json('id');
        $atencionId = DB::table('emergencias')->where('id', $id)->value('atencion_id');
        $this->assertDatabaseHas('atenciones', ['id' => $atencionId, 'tipo' => 'emergencia', 'estado' => 'en_curso']);
        $this->assertDatabaseHas('emergencias', ['id' => $id, 'ubicacion_activa' => '2', 'paciente_activo_ci' => '987654']);
        $this->getJson('/api/emergencias')->assertOk()->assertJsonPath('cuentas.0.id', $id)
            ->assertJsonPath('cuentas.0.identidad.nombres', 'Paciente');
        $this->postJson('/api/emergencias', ['cubiculo' => '2'])->assertUnprocessable();
    }

    public function test_registra_evalua_y_cierra_emergencia_sin_tablas_anticipadas(): void
    {
        $this->withoutMiddleware(VerificarSesionCompartida::class);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertCreated()->json('id');
        $this->assertDatabaseHas('emergencias', ['id' => $id, 'ubicacion_activa' => '1']);
        $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertUnprocessable();
        $evaluacion = $this->postJson("/api/emergencias/{$id}/evaluacion", ['revision' => 1, 'campos' => [
            'motivo' => 'Control', 'antecedentesVivienda' => 'Propia', 'antecedentesHipertensionRenal' => 'Sí',
            'antecedentesQuirurgicos' => 'Cirugía previa', 'antecedentesAlergicos' => 'Alergia a medicamentos',
            'antecedentesFamiliares' => 'Diabetes',
        ]])->assertOk()->assertJsonPath('evaluaciones.0.campos.motivo', 'Control');
        $evaluacionId = $evaluacion->json('evaluaciones.0.id');
        $this->postJson("/api/emergencias/{$id}/evaluacion/{$evaluacionId}/editar", ['revision' => 2, 'campos' => ['antecedentesAlergicos' => 'Desconocido']])
            ->assertOk()->assertJsonPath('evaluaciones.0.campos.motivo', 'Control')->assertJsonPath('evaluaciones.0.campos.antecedentesAlergicos', 'Desconocido');
        $this->getJson('/api/emergencias')->assertOk()
            ->assertJsonPath('cuentas.0.evaluaciones.0.campos.antecedentesVivienda', 'Propia')
            ->assertJsonPath('cuentas.0.evaluaciones.0.campos.antecedentesHipertensionRenal', 'Sí')
            ->assertJsonPath('cuentas.0.evaluaciones.0.campos.antecedentesQuirurgicos', 'Cirugía previa')
            ->assertJsonPath('cuentas.0.evaluaciones.0.campos.antecedentesAlergicos', 'Desconocido')
            ->assertJsonPath('cuentas.0.evaluaciones.0.campos.antecedentesFamiliares', 'Diabetes');
        $this->postJson("/api/emergencias/{$id}/cierre", ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])
            ->assertOk()->assertJsonPath('cierre.causaEgreso', 'hospitalaria');
        $atencion = DB::table('emergencias')->where('id', $id)->value('atencion_id');
        $this->assertDatabaseHas('atenciones', ['id' => $atencion, 'estado' => 'finalizada']);
        $this->assertDatabaseHas('emergencias', ['id' => $id, 'ubicacion_activa' => null]);
        $this->postJson('/api/emergencias', ['cubiculo' => '1'])->assertCreated();
    }

    public function test_balance_y_traslado_persisten_y_rechazan_cambios_despues_del_cierre(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '5'])->assertCreated()->json('id');
        $balance = ['id' => '11111111-1111-4111-8111-111111111111', 'tipo' => 'ingreso', 'volumen' => 250, 'detalle' => 'Suero', 'anotacion' => 'Observación', 'fecha' => '2026-11-17', 'hora' => '10:30'];
        $traslado = ['fecha' => '2026-11-18', 'hora' => '11:30', 'destino' => 'Hospital', 'motivo' => 'Transferencia'];
        $this->postJson("/api/emergencias/{$id}/balance-hidrico", $balance)->assertCreated()->assertJsonPath('balanceHidrico.0.volumen', 250);
        $this->postJson("/api/emergencias/{$id}/balance-hidrico", $balance)->assertCreated()->assertJsonCount(1, 'balanceHidrico');
        $this->postJson("/api/emergencias/{$id}/balance-hidrico", [...$balance, 'volumen' => 0])->assertUnprocessable();
        $this->postJson("/api/emergencias/{$id}/traslado-ambulancia", $traslado)->assertOk()->assertJsonPath('trasladoAmbulancia.destino', 'Hospital');
        $this->getJson('/api/emergencias')->assertOk()->assertJsonPath('cuentas.0.balanceHidrico.0.detalle', 'Suero')->assertJsonPath('cuentas.0.trasladoAmbulancia.hora', '11:30');
        $this->assertDatabaseHas('emergencia_balance_hidrico', ['emergencia_id' => $id, 'volumen' => 250]);
        $this->assertDatabaseHas('emergencia_traslados_ambulancia', ['emergencia_id' => $id, 'destino' => 'Hospital']);
        $this->postJson("/api/emergencias/{$id}/cierre", ['condicionEgreso' => 'vivo', 'causaEgreso' => 'hospitalaria'])->assertOk();
        $this->postJson("/api/emergencias/{$id}/balance-hidrico", $balance)->assertUnprocessable();
        $this->postJson("/api/emergencias/{$id}/traslado-ambulancia", $traslado)->assertUnprocessable();
    }

    public function test_crea_cuenta_y_registra_consumo_sin_duplicar_reintentos(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->mock(CatalogoCompartido::class)->shouldReceive('obtener')->andReturn([
            ['id' => 'servicio-1', 'codigo' => 'SRV-1', 'nombre' => 'Laboratorio', 'tipo' => 'servicio', 'unidad' => 'Unidad', 'precio' => 25],
        ]);
        $respuesta = $this->postJson('/api/emergencias', ['cubiculo' => '6'])->assertCreated();
        $id = $respuesta->json('id');
        $cuentaId = $respuesta->json('cuenta.id');
        $this->assertDatabaseHas('cuentas_atencion_emergencia', ['id' => $cuentaId, 'emergencia_id' => $id, 'estado' => 'abierta']);
        $consumo = ['itemId' => 'servicio-1', 'cantidad' => 2, 'porHora' => false, 'registroId' => '22222222-2222-4222-8222-222222222222'];
        $this->postJson("/api/emergencias/{$id}/consumos", $consumo)->assertOk()->assertJsonCount(1, 'consumos');
        $this->postJson("/api/emergencias/{$id}/consumos", $consumo)->assertOk()->assertJsonCount(1, 'consumos');
        $this->assertDatabaseHas('cargos_atencion_emergencia', ['cuenta_id' => $cuentaId, 'registro_id' => $consumo['registroId'], 'item_id' => 'servicio-1', 'vigente' => true]);
        $this->assertDatabaseCount('cargos_atencion_emergencia', 1);
        config(['services.contable.token' => str_repeat('x', 48)]);
        $this->getJson('/api/integraciones/contable/cuentas-emergencias')->assertUnauthorized();
        $this->withToken(str_repeat('x', 48))->getJson('/api/integraciones/contable/cuentas-emergencias')->assertOk()
            ->assertJsonPath('data.0.id', $cuentaId)->assertJsonPath('data.0.paciente', 'Paciente sin identificar')
            ->assertJsonPath('data.0.total', 50)->assertJsonPath('data.0.detalles.0.concepto', 'Laboratorio');
    }

    public function test_aplicacion_de_orden_e_insumo_de_enfermeria_generan_cargos(): void
    {
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        Schema::create('roles_cuenta_prueba', function (Blueprint $tabla): void { $tabla->string('codigo'); $tabla->string('nombre'); });
        $this->aislarUsuarios();
        $schema = Schema::connection('usuarios');
        $schema->create('sucursales_inventario', function (Blueprint $t): void { $t->id(); $t->boolean('activo'); });
        $schema->create('almacenes_inventario', function (Blueprint $t): void { $t->id(); $t->integer('sucursal_id'); $t->boolean('activo'); });
        $schema->create('lotes_items', function (Blueprint $t): void { $t->id(); $t->integer('item_id'); $t->date('vencimiento')->nullable(); });
        $schema->create('existencias_lote', function (Blueprint $t): void { $t->id(); $t->integer('almacen_id'); $t->integer('lote_id'); $t->decimal('cantidad', 18, 3); $t->timestamps(); });
        $schema->create('clinico_consumos_inventario', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('emergencia_id'); $t->uuid('usuario_id'); $t->integer('almacen_id'); $t->integer('item_id');
            $t->decimal('cantidad', 18, 3); $t->string('huella'); $t->json('datos_clinicos'); $t->json('lotes'); $t->timestamp('sincronizado_en')->nullable(); $t->timestamps();
        });
        $db = DB::connection('usuarios');
        $db->table('sucursales_inventario')->insert(['id' => 1, 'activo' => true]);
        $db->table('almacenes_inventario')->insert(['id' => 1, 'sucursal_id' => 1, 'activo' => true]);
        $db->table('lotes_items')->insert(['id' => 1, 'item_id' => 1, 'vencimiento' => '2099-01-01']);
        $db->table('existencias_lote')->insert(['almacen_id' => 1, 'lote_id' => 1, 'cantidad' => 5]);
        $acceso = $this->mock(AccesoCompartido::class);
        $acceso->shouldReceive('roles')->andReturn(DB::table('roles_cuenta_prueba as r'));
        $acceso->shouldReceive('conexion')->andReturn($db);
        $acceso->shouldReceive('almacenesPermitidos')->andReturn([1]);
        $this->mock(CatalogoCompartido::class)->shouldReceive('obtener')->andReturn([
            ['id' => 'servicio-1', 'codigo' => 'SRV-1', 'nombre' => 'Laboratorio', 'tipo' => 'servicio', 'unidad' => 'Unidad', 'precio' => 25],
            ['id' => 'item-1', 'contableId' => 1, 'codigo' => 'ITM-1', 'nombre' => 'Guantes', 'tipo' => 'insumo', 'unidad' => 'Par', 'precio' => 10],
        ]);
        $this->actingAs(new GenericUser(['id' => '11111111-1111-4111-8111-111111111111', 'nombres' => 'Personal', 'apellidos' => 'Prueba']));
        $id = $this->postJson('/api/emergencias', ['cubiculo' => '6'])->assertCreated()->json('id');
        $orden = $this->postJson("/api/emergencias/{$id}/orden", ['tipo' => 'laboratorio', 'titulo' => 'Laboratorio', 'itemId' => 'servicio-1'])->assertOk()->json('ordenes.0.id');
        $aplicacion = ['ordenId' => $orden, 'revision' => 1, 'registroId' => '22222222-2222-4222-8222-222222222222', 'itemId' => 'servicio-1', 'cantidad' => 2];
        $this->postJson("/api/emergencias/{$id}/aplicar-orden", $aplicacion)->assertOk()->assertJsonCount(1, 'consumos')->assertJsonPath('ordenes.0.estado', 'aplicada');
        $this->postJson("/api/emergencias/{$id}/aplicar-orden", $aplicacion)->assertOk()->assertJsonCount(1, 'consumos');
        $uso = ['tipo' => 'insumo', 'itemId' => 'item-1', 'detalle' => 'Guantes', 'cantidad' => 3, 'almacenId' => 1, 'solicitudId' => '33333333-3333-4333-8333-333333333333'];
        $this->postJson("/api/emergencias/{$id}/uso-enfermeria", $uso)->assertOk()->assertJsonCount(2, 'consumos');
        $this->postJson("/api/emergencias/{$id}/uso-enfermeria", $uso)->assertOk()->assertJsonCount(2, 'consumos');
        $this->assertEquals(2, $db->table('existencias_lote')->where('id', 1)->value('cantidad'));
        $this->postJson("/api/emergencias/{$id}/uso-enfermeria", [...$uso, 'cantidad' => 1])->assertStatus(409);
        $this->assertEquals(2, $db->table('existencias_lote')->where('id', 1)->value('cantidad'));
        $ingreso = json_decode(DB::table('emergencias')->where('id', $id)->value('datos_ingreso'), true);
        $ingreso['consumos'] = array_values(array_filter($ingreso['consumos'], fn ($consumo) => $consumo['registroId'] !== $uso['solicitudId']));
        $ingreso['registrosEnfermeria'] = [];
        DB::table('emergencias')->where('id', $id)->update(['datos_ingreso' => json_encode($ingreso)]);
        DB::table('cargos_atencion_emergencia')->where('registro_id', $uso['solicitudId'])->delete();
        $db->table('clinico_consumos_inventario')->where('id', $uso['solicitudId'])->update(['sincronizado_en' => null]);
        $this->postJson('/api/emergencias/recuperar-consumos-inventario')->assertOk()->assertJsonPath('recuperados', 1);
        $this->getJson('/api/emergencias')->assertOk()->assertJsonCount(2, 'cuentas.0.consumos');
        $this->assertEquals(2, $db->table('existencias_lote')->where('id', 1)->value('cantidad'));
        $this->postJson("/api/emergencias/{$id}/uso-enfermeria", [...$uso, 'cantidad' => 4, 'solicitudId' => '44444444-4444-4444-8444-444444444444'])->assertUnprocessable();
        $this->assertEquals(2, $db->table('existencias_lote')->where('id', 1)->value('cantidad'));
        $this->assertDatabaseCount('cargos_atencion_emergencia', 2);
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
