<?php

namespace App\Flows\Farmacia;

use Illuminate\Support\Facades\DB;

class CatalogoCompartido
{
    public function obtener(): array
    {
        $db = DB::connection('usuarios');
        $existencias = $db->table('existencias_lote as e')->join('lotes_items as l', 'l.id', '=', 'e.lote_id')
            ->selectRaw('l.item_id, SUM(e.cantidad) as cantidad')->groupBy('l.item_id')->pluck('cantidad', 'item_id');
        $productos = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')->join('unidades_inventario as u', 'u.id', '=', 'i.unidad_id')
            ->leftJoin('categorias_inventario as c', 'c.id', '=', 'g.categoria_id')
            ->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->where('t.activo', true)->where('u.activo', true)
            ->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])->orderBy('i.nombre_comercial')->orderBy('i.id')
            ->get(['i.id', 'i.nombre_comercial', 'i.marca', 'i.presentacion', 'i.precio_1', 'i.precio_2', 'i.precio_3', 'g.nombre as generico', 't.tipo_base', 'u.nombre as unidad', 'c.nombre as categoria'])
            ->map(fn (object $i): array => ['id' => 'item-'.$i->id, 'contableId' => $i->id, 'origen' => 'CONTABLE', 'codigo' => 'ITM-'.str_pad((string) $i->id, 6, '0', STR_PAD_LEFT),
                'nombre' => $i->nombre_comercial, 'generico' => $i->generico, 'marca' => $i->marca, 'presentacion' => $i->presentacion,
                'tipo' => strtolower($i->tipo_base), 'unidad' => $i->unidad, 'categoria' => $i->categoria ?? 'Sin categoría',
                'precio' => (float) $i->precio_1, 'precio1' => $i->precio_1, 'precio2' => $i->precio_2, 'precio3' => $i->precio_3, 'stock' => (float) ($existencias[$i->id] ?? 0)])->all();
        $servicios = $db->table('servicios as s')->join('tipos_inventario as t', 't.id', '=', 's.tipo_id')
            ->join('unidades_inventario as u', 'u.id', '=', 's.unidad_id')->leftJoin('categorias_inventario as c', 'c.id', '=', 's.categoria_id')
            ->where('s.estado', 'ACTIVO')->where('t.activo', true)->where('u.activo', true)->orderBy('s.nombre')->orderBy('s.id')
            ->get(['s.id', 's.nombre', 's.precio_venta', 'u.nombre as unidad', 'c.nombre as categoria'])
            ->map(fn (object $s): array => ['id' => 'servicio-'.$s->id, 'contableId' => $s->id, 'origen' => 'CONTABLE', 'codigo' => 'SRV-'.str_pad((string) $s->id, 6, '0', STR_PAD_LEFT),
                'nombre' => $s->nombre, 'tipo' => 'servicio', 'unidad' => $s->unidad, 'categoria' => $s->categoria ?? 'Sin categoría', 'precio' => (float) $s->precio_venta])->all();

        return [...$productos, ...$servicios];
    }
}
