<?php

namespace Tests\Feature;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Farmacia\TomiInventario;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TomiInventarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.usuarios' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('usuarios');
        $this->crearEsquemaInventario();

        $acceso = \Mockery::mock(AccesoCompartido::class);
        $acceso->shouldReceive('almacenesPermitidos')->andReturn([1]);
        app()->instance(AccesoCompartido::class, $acceso);
        $this->crearProductoConLotes();
    }

    protected function tearDown(): void
    {
        DB::purge('usuarios');
        parent::tearDown();
    }

    public function test_consulta_lotes_que_vencen_pronto_con_fecha_y_ubicacion(): void
    {
        $tomi = app(TomiInventario::class);
        $consulta = '¿Qué productos vencen pronto?';

        $this->assertTrue($tomi->esConsultaInventario($consulta));
        $respuesta = $tomi->responder($consulta);

        $this->assertStringContainsString('Lotes con existencias que vencen en los próximos 30 días:', $respuesta);
        $this->assertStringContainsString('Paracetamol 500 mg (Genérico)', $respuesta);
        $this->assertStringContainsString('lote L-PRONTO', $respuesta);
        $this->assertStringContainsString('Almacén principal — Estante A / Repisa 2', $respuesta);
        $this->assertStringNotContainsString('L-LEJANO', $respuesta);
        $this->assertStringNotContainsString('L-VENCIDO', $respuesta);
    }

    public function test_consulta_vencidos_sin_confundirlos_con_proximos_a_vencer(): void
    {
        $respuesta = app(TomiInventario::class)->responder('¿Qué productos están vencidos?');

        $this->assertStringContainsString('Lotes vencidos con existencias:', $respuesta);
        $this->assertStringContainsString('lote L-VENCIDO', $respuesta);
        $this->assertStringNotContainsString('L-PRONTO', $respuesta);
    }

    public function test_interpreta_preguntas_naturales_de_stock_sin_buscar_las_palabras_de_la_pregunta(): void
    {
        $tomi = app(TomiInventario::class);
        $consulta = '¿Cuánto paracetamol queda?';

        $this->assertTrue($tomi->esConsultaInventario($consulta));
        $this->assertStringContainsString('stock vigente: 19.', $tomi->responder($consulta));
    }

    private function crearEsquemaInventario(): void
    {
        $db = DB::connection('usuarios');
        $db->statement('CREATE TABLE productos_base (id INTEGER PRIMARY KEY, tipo_id INTEGER, estado TEXT, nombre TEXT)');
        $db->statement('CREATE TABLE tipos_inventario (id INTEGER PRIMARY KEY, tipo_base TEXT)');
        $db->statement('CREATE TABLE items_inventario (id INTEGER PRIMARY KEY, producto_base_id INTEGER, estado TEXT, nombre_comercial TEXT, marca TEXT, presentacion TEXT, codigo_barras TEXT, clave TEXT)');
        $db->statement('CREATE TABLE lotes_items (id INTEGER PRIMARY KEY, item_id INTEGER, numero TEXT, vencimiento TEXT)');
        $db->statement('CREATE TABLE existencias_lote (id INTEGER PRIMARY KEY, lote_id INTEGER, almacen_id INTEGER, cantidad REAL)');
        $db->statement('CREATE TABLE almacenes_inventario (id INTEGER PRIMARY KEY, sucursal_id INTEGER, nombre TEXT, activo INTEGER)');
        $db->statement('CREATE TABLE sucursales_inventario (id INTEGER PRIMARY KEY, nombre TEXT, activo INTEGER)');
        $db->statement('CREATE TABLE item_almacen_ubicaciones (id INTEGER PRIMARY KEY, item_id INTEGER, almacen_id INTEGER, ubicacion TEXT)');
    }

    private function crearProductoConLotes(): void
    {
        $db = DB::connection('usuarios');
        $db->table('tipos_inventario')->insert(['id' => 1, 'tipo_base' => 'PRODUCTO']);
        $db->table('productos_base')->insert(['id' => 1, 'tipo_id' => 1, 'estado' => 'ACTIVO', 'nombre' => 'Paracetamol']);
        $db->table('items_inventario')->insert([
            'id' => 1, 'producto_base_id' => 1, 'estado' => 'ACTIVO', 'nombre_comercial' => 'Paracetamol',
            'marca' => 'Genérico', 'presentacion' => '500 mg', 'codigo_barras' => 'FAR001234', 'clave' => 'PAR001',
        ]);
        $db->table('sucursales_inventario')->insert(['id' => 1, 'nombre' => 'Sucursal Central', 'activo' => 1]);
        $db->table('almacenes_inventario')->insert(['id' => 1, 'sucursal_id' => 1, 'nombre' => 'Almacén principal', 'activo' => 1]);
        $db->table('item_almacen_ubicaciones')->insert(['item_id' => 1, 'almacen_id' => 1, 'ubicacion' => 'Estante A / Repisa 2']);

        foreach ([
            ['id' => 1, 'numero' => 'L-PRONTO', 'vence' => now()->addDays(10)->toDateString(), 'cantidad' => 8],
            ['id' => 2, 'numero' => 'L-LEJANO', 'vence' => now()->addDays(60)->toDateString(), 'cantidad' => 11],
            ['id' => 3, 'numero' => 'L-VENCIDO', 'vence' => now()->subDays(5)->toDateString(), 'cantidad' => 2],
        ] as $lote) {
            $db->table('lotes_items')->insert(['id' => $lote['id'], 'item_id' => 1, 'numero' => $lote['numero'], 'vencimiento' => $lote['vence']]);
            $db->table('existencias_lote')->insert(['lote_id' => $lote['id'], 'almacen_id' => 1, 'cantidad' => $lote['cantidad']]);
        }
    }
}
