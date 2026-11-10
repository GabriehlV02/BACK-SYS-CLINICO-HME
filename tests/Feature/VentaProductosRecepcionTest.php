<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Middleware\RegistrarAuditoriaAccion;
use App\Http\Middleware\VerificarSesionCompartida;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VentaProductosRecepcionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerificarSesionCompartida::class, RegistrarAuditoriaAccion::class]);
        $this->actingAs(new GenericUser(['id' => 'user']));
        $db = DB::connection();
        $tablas = [
            'usuarios_sistema (id text primary key, nombres text, apellidos text)',
            'roles (id text, activo boolean)',
            'rol_usuario (usuario_id text, rol_id text)',
            'rol_sistema (rol_id text, sistema_id text)',
            'sistemas (id text, codigo text, activo boolean)',
            'permiso_rol (rol_id text, permiso_id text)',
            'permisos (id text, sistema_id text, codigo text, activo boolean)',
            'sucursales_inventario (id integer primary key, nombre text, activo boolean)',
            'cajas (id integer primary key, nombre text, codigo text, sucursal_id integer, tipos_item text, activo boolean)',
            'almacenes_inventario (id integer primary key, nombre text, sucursal_id integer, tipos_item text, activo boolean)',
            'almacen_caja (caja_id integer, almacen_id integer)',
            'clientes (id text primary key)',
            'tipos_inventario (id integer primary key, tipo_base text, activo boolean)',
            'productos_base (id integer primary key, tipo_id integer, estado text)',
            'items_inventario (id integer primary key, producto_base_id integer, nombre_comercial text, precio_1 numeric, estado text)',
            'lotes_items (id integer primary key, item_id integer, vencimiento text)',
            'existencias_lote (id integer primary key, lote_id integer, almacen_id integer, cantidad numeric)',
            'carritos_venta (id text primary key, codigo text, cliente_id text, usuario_sistema_id text, sucursal_id integer, caja_id integer, estado text, moneda text, subtotal numeric, descuento numeric, total numeric, observaciones text, cobrada_en text, created_at text, updated_at text)',
            'carrito_venta_detalles (id text primary key, carrito_venta_id text, codigo_item text, nombre_item text, tipo_item text, cantidad numeric, precio_unitario numeric, descuento numeric, total numeric, created_at text, updated_at text)',
            'ventas_clinicas_solicitudes (solicitud_id text primary key, usuario_id text, venta_id text, huella text, pagos text, cambio numeric, created_at text, updated_at text)',
            'ventas_clinicas_salidas (id integer primary key, venta_id text, lote_id integer, almacen_id integer, cantidad numeric, created_at text, updated_at text)',
        ];
        foreach ($tablas as $tabla) {
            $db->statement('CREATE TABLE '.$tabla);
        }
        $db->table('usuarios_sistema')->insert(['id' => 'user']);
        $db->table('sucursales_inventario')->insert(['id' => 1, 'nombre' => 'Central', 'activo' => true]);
        $db->table('cajas')->insert(['id' => 1, 'nombre' => 'Caja', 'codigo' => 'CAJ', 'sucursal_id' => 1, 'tipos_item' => '["Productos"]', 'activo' => true]);
        $db->table('almacenes_inventario')->insert(['id' => 1, 'nombre' => 'Almacén', 'sucursal_id' => 1, 'tipos_item' => '["Productos","Insumos"]', 'activo' => true]);
        $db->table('almacen_caja')->insert(['caja_id' => 1, 'almacen_id' => 1]);
        $db->table('clientes')->insert(['id' => '11111111-1111-4111-8111-111111111111']);
        $db->table('tipos_inventario')->insert(['id' => 1, 'tipo_base' => 'PRODUCTO', 'activo' => true]);
        $db->table('productos_base')->insert(['id' => 1, 'tipo_id' => 1, 'estado' => 'ACTIVO']);
        $db->table('items_inventario')->insert(['id' => 1, 'producto_base_id' => 1, 'nombre_comercial' => 'Producto', 'precio_1' => 5, 'estado' => 'ACTIVO']);
        $db->table('lotes_items')->insert(['id' => 1, 'item_id' => 1, 'vencimiento' => null]);
        $db->table('existencias_lote')->insert(['id' => 1, 'lote_id' => 1, 'almacen_id' => 1, 'cantidad' => 3]);
        $mock = $this->mock(AccesoCompartido::class);
        $mock->shouldReceive('conexion')->andReturn($db);
        $mock->shouldReceive('esAdministrador')->andReturn(true);
    }

    private function datos(int $cantidad = 2): array
    {
        return ['solicitud_id' => '22222222-2222-4222-8222-222222222222', 'cliente_id' => '11111111-1111-4111-8111-111111111111', 'caja_id' => 1, 'almacen_id' => 1, 'descuento' => 0, 'detalles' => [['item_id' => 1, 'cantidad' => $cantidad, 'precio_unitario' => 5]], 'pagos' => [['metodo' => 'Efectivo', 'monto' => $cantidad * 5]]];
    }

    public function test_venta_descuenta_stock_y_reintento_no_duplica(): void
    {
        $url = '/api/v1/recepcion/ventas';
        $id = $this->postJson($url, $this->datos())->assertCreated()->json('data.id');
        $this->postJson($url, $this->datos())->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('carritos_venta')->count());
        $this->assertEquals(1, DB::table('existencias_lote')->value('cantidad'));
        $this->assertSame(1, DB::table('ventas_clinicas_salidas')->count());
    }

    public function test_stock_insuficiente_revierte_la_venta(): void
    {
        $this->postJson('/api/v1/recepcion/ventas', $this->datos(4))->assertUnprocessable();
        $this->assertSame(0, DB::table('carritos_venta')->count());
        $this->assertEquals(3, DB::table('existencias_lote')->value('cantidad'));
    }

    public function test_rechaza_un_precio_desactualizado(): void
    {
        $datos = $this->datos();
        $datos['detalles'][0]['precio_unitario'] = 4;
        $this->postJson('/api/v1/recepcion/ventas', $datos)->assertUnprocessable();
        $this->assertSame(0, DB::table('carritos_venta')->count());
    }

    public function test_usuario_sin_permiso_no_puede_vender(): void
    {
        $acceso = \Mockery::mock(AccesoCompartido::class);
        $acceso->shouldReceive('conexion')->andReturn(DB::connection());
        $acceso->shouldReceive('esAdministrador')->andReturn(false);
        $this->app->instance(AccesoCompartido::class, $acceso);
        $this->postJson('/api/v1/recepcion/ventas', $this->datos())->assertForbidden();
        $this->assertSame(0, DB::table('carritos_venta')->count());
    }

    public function test_historial_muestra_solo_ventas_del_cliente_y_sus_detalles(): void
    {
        $this->postJson('/api/v1/recepcion/ventas', $this->datos())->assertCreated();
        $otroCliente = '33333333-3333-4333-8333-333333333333';
        DB::table('clientes')->insert(['id' => $otroCliente]);
        $this->getJson('/api/v1/recepcion/clientes/'.$otroCliente.'/ventas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/recepcion/clientes/'.$this->datos()['cliente_id'].'/ventas')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.detalles.0.nombre_item', 'Producto')
            ->assertJsonPath('data.0.pagos.0.metodo', 'Efectivo');
    }

    public function test_historial_requiere_permiso_de_ventas(): void
    {
        $acceso = \Mockery::mock(AccesoCompartido::class);
        $acceso->shouldReceive('conexion')->andReturn(DB::connection());
        $acceso->shouldReceive('esAdministrador')->andReturn(false);
        $this->app->instance(AccesoCompartido::class, $acceso);
        $this->getJson('/api/v1/recepcion/clientes/'.$this->datos()['cliente_id'].'/ventas')->assertForbidden();
    }

    private function agregarInsumo(): void
    {
        DB::table('tipos_inventario')->insert(['id' => 2, 'tipo_base' => 'INSUMO', 'activo' => true]);
        DB::table('productos_base')->insert(['id' => 2, 'tipo_id' => 2, 'estado' => 'ACTIVO']);
        DB::table('items_inventario')->insert(['id' => 2, 'producto_base_id' => 2, 'nombre_comercial' => 'Guantes', 'precio_1' => 3, 'estado' => 'ACTIVO']);
        DB::table('lotes_items')->insert(['id' => 2, 'item_id' => 2, 'vencimiento' => null]);
        DB::table('existencias_lote')->insert(['id' => 2, 'lote_id' => 2, 'almacen_id' => 1, 'cantidad' => 5]);
    }

    public function test_venta_mixta_registra_insumo_y_descuenta_su_lote(): void
    {
        $this->agregarInsumo();
        DB::table('cajas')->where('id', 1)->update(['tipos_item' => '["Productos","Insumos"]']);
        $datos = $this->datos();
        $datos['detalles'][] = ['item_id' => 2, 'cantidad' => 2, 'precio_unitario' => 3];
        $datos['pagos'][0]['monto'] = 16;
        $url = '/api/v1/recepcion/ventas';
        $id = $this->postJson($url, $datos)->assertCreated()->json('data.id');
        $this->postJson($url, $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('carritos_venta')->count());
        $this->assertEquals(3, DB::table('existencias_lote')->where('id', 2)->value('cantidad'));
        $this->assertDatabaseHas('carrito_venta_detalles', ['carrito_venta_id' => $id, 'nombre_item' => 'Guantes', 'tipo_item' => 'Insumo']);
        $this->assertDatabaseHas('ventas_clinicas_salidas', ['venta_id' => $id, 'lote_id' => 2, 'almacen_id' => 1, 'cantidad' => 2]);
    }

    public function test_rechaza_insumos_no_permitidos_por_caja_o_almacen(): void
    {
        $this->agregarInsumo();
        $datos = $this->datos();
        $datos['detalles'] = [['item_id' => 2, 'cantidad' => 2, 'precio_unitario' => 3]];
        $datos['pagos'][0]['monto'] = 6;
        $url = '/api/v1/recepcion/ventas';
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('caja_id');
        DB::table('cajas')->where('id', 1)->update(['tipos_item' => '["Insumos"]']);
        DB::table('almacenes_inventario')->where('id', 1)->update(['tipos_item' => '["Productos"]']);
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('almacen_id');
        $this->assertSame(0, DB::table('carritos_venta')->count());
        $this->assertEquals(5, DB::table('existencias_lote')->where('id', 2)->value('cantidad'));
    }

    public function test_stock_insuficiente_de_insumo_revierte_venta_mixta(): void
    {
        $this->agregarInsumo();
        DB::table('cajas')->where('id', 1)->update(['tipos_item' => '["Productos","Insumos"]']);
        $datos = $this->datos();
        $datos['detalles'][] = ['item_id' => 2, 'cantidad' => 6, 'precio_unitario' => 3];
        $datos['pagos'][0]['monto'] = 28;
        $this->postJson('/api/v1/recepcion/ventas', $datos)->assertUnprocessable()->assertJsonValidationErrors('detalles');
        $this->assertSame(0, DB::table('carritos_venta')->count());
        $this->assertEquals(3, DB::table('existencias_lote')->where('id', 1)->value('cantidad'));
        $this->assertEquals(5, DB::table('existencias_lote')->where('id', 2)->value('cantidad'));
    }

    public function test_cobro_de_imagenologia_crea_orden_y_el_reintento_no_duplica(): void
    {
        $db = DB::connection();
        $db->statement('ALTER TABLE clientes ADD COLUMN paciente_id text');
        $db->statement('ALTER TABLE tipos_inventario ADD COLUMN nombre text');
        $db->statement('CREATE TABLE pacientes (id text primary key)');
        $db->statement('CREATE TABLE categorias_inventario (id integer primary key, nombre text)');
        $db->statement('CREATE TABLE servicios (id integer primary key, nombre text, precio_venta numeric, tipo_id integer, categoria_id integer, estado text)');
        $db->statement('CREATE TABLE ventas_clinicas_ordenes (id integer primary key autoincrement, venta_detalle_id text, unidad integer, paciente_id text, tipo text, nombre text, procesada_en text, created_at text, updated_at text)');
        $db->statement('CREATE TABLE estudios (id text primary key, paciente_id text, codigo text unique, tipo text, nombre text, estado text, estado_detalle text, solicitado_por text, solicitado_en text, venta_detalle_id text, venta_detalle_unidad integer, created_at text, updated_at text, unique (venta_detalle_id, venta_detalle_unidad))');
        $db->statement('CREATE TABLE estudios_radiografia (id text primary key, estudio_id text unique, created_at text, updated_at text)');
        $paciente = '33333333-3333-4333-8333-333333333333';
        $db->table('pacientes')->insert(['id' => $paciente]);
        $db->table('clientes')->where('id', $this->datos()['cliente_id'])->update(['paciente_id' => $paciente]);
        $db->table('tipos_inventario')->insert(['id' => 2, 'nombre' => 'Imagenología', 'tipo_base' => 'SERVICIO', 'activo' => true]);
        $db->table('servicios')->insert(['id' => 2, 'nombre' => 'Radiografía de tórax AP', 'precio_venta' => 120, 'tipo_id' => 2, 'estado' => 'ACTIVO']);
        $db->table('cajas')->where('id', 1)->update(['tipos_item' => '["Productos","Servicios"]']);
        $datos = $this->datos();
        $datos['detalles'] = [['servicio_id' => 2, 'cantidad' => 1, 'precio_unitario' => 120]];
        $datos['pagos'][0]['monto'] = 120;
        $id = $this->postJson('/api/v1/recepcion/ventas', $datos)->assertCreated()->assertJsonPath('ordenes_pendientes', false)->json('data.id');
        $this->postJson('/api/v1/recepcion/ventas', $datos)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('ordenes_pendientes', false);
        $this->getJson('/api/v1/recepcion/ventas/solicitud/'.$datos['solicitud_id'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('carritos_venta', 1);
        $this->assertDatabaseCount('estudios', 1);
        $this->assertDatabaseHas('estudios', ['paciente_id' => $paciente, 'tipo' => 'RADIOGRAFIA', 'nombre' => 'Radiografía de tórax AP']);
        $this->assertDatabaseCount('estudios_radiografia', 1);
        $db->table('ventas_clinicas_ordenes')->update(['procesada_en' => null]);
        $this->postJson('/api/v1/recepcion/ordenes/recuperar')->assertOk()->assertJsonPath('ventas_revisadas', 1)->assertJsonPath('pendientes', 0);
        $this->assertDatabaseCount('estudios', 1);
        $db->statement('DROP TABLE estudios_radiografia');
        $datos['solicitud_id'] = '44444444-4444-4444-8444-444444444444';
        $this->postJson('/api/v1/recepcion/ventas', $datos)->assertCreated()->assertJsonPath('ordenes_pendientes', true);
        $this->assertDatabaseCount('carritos_venta', 2);
        $this->assertDatabaseCount('estudios', 1);
        $db->statement('CREATE TABLE estudios_radiografia (id text primary key, estudio_id text unique, created_at text, updated_at text)');
        $this->postJson('/api/v1/recepcion/ordenes/recuperar')->assertOk()->assertJsonPath('pendientes', 0);
        $this->assertDatabaseCount('estudios', 2);
        $this->postJson('/api/v1/recepcion/ventas', $datos)->assertCreated()->assertJsonPath('ordenes_pendientes', false);
        $this->assertDatabaseCount('carritos_venta', 2);
    }
}
