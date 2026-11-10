<?php

namespace Tests\Feature;

use App\Flows\Atenciones\SincronizarOrdenVenta;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SincronizarOrdenVentaTest extends TestCase
{
    public function test_reintentar_una_orden_confirmada_no_duplica_el_estudio(): void
    {
        $db = DB::connection();
        $db->statement('CREATE TABLE carritos_venta (id text primary key, estado text)');
        $db->statement('CREATE TABLE carrito_venta_detalles (id text primary key, carrito_venta_id text)');
        $db->statement('CREATE TABLE ventas_clinicas_ordenes (id integer primary key, venta_detalle_id text, unidad integer, paciente_id text, tipo text, nombre text, procesada_en text, updated_at text)');
        $db->statement('CREATE TABLE estudios (id text primary key, paciente_id text, codigo text unique, tipo text, nombre text, estado text, estado_detalle text, solicitado_por text, solicitado_en text, venta_detalle_id text, venta_detalle_unidad integer, created_at text, updated_at text, unique (venta_detalle_id, venta_detalle_unidad))');
        $db->statement('CREATE TABLE estudios_radiografia (id text primary key, estudio_id text unique, created_at text, updated_at text)');
        $db->table('carritos_venta')->insert(['id' => 'venta', 'estado' => 'COMPLETADA']);
        $db->table('carrito_venta_detalles')->insert(['id' => 'detalle', 'carrito_venta_id' => 'venta']);
        $db->table('ventas_clinicas_ordenes')->insert(['id' => 1, 'venta_detalle_id' => 'detalle', 'unidad' => 1, 'paciente_id' => 'paciente', 'tipo' => 'RADIOGRAFIA', 'nombre' => 'Radiografía de tórax']);
        $servicio = new SincronizarOrdenVenta;
        $servicio->procesar($db, 'venta');
        $db->table('ventas_clinicas_ordenes')->where('id', 1)->update(['procesada_en' => null]);
        $servicio->procesar($db, 'venta');
        $this->assertDatabaseCount('estudios', 1);
        $this->assertDatabaseCount('estudios_radiografia', 1);
        $this->assertNotNull($db->table('ventas_clinicas_ordenes')->where('id', 1)->value('procesada_en'));
    }
}
