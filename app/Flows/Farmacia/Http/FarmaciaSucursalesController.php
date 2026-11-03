<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmaciaSucursalesController
{
    public function index(AccesoCompartido $acceso): JsonResponse
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $sucursales = $db->table('sucursales_inventario as s')->where('s.activo', true)
            ->when($permitidos !== null, function ($query) use ($permitidos): void {
                $query->whereExists(function ($subquery) use ($permitidos): void {
                    $subquery->selectRaw('1')->from('almacenes_inventario as a')->whereColumn('a.sucursal_id', 's.id')->where('a.activo', true);
                    if ($permitidos === []) {
                        $subquery->whereRaw('1 = 0');
                    } else {
                        $subquery->whereIn('a.id', $permitidos);
                    }
                });
            })->orderBy('s.nombre')->get(['s.id', 's.nombre']);
        $sucursales = $sucursales->map(function (object $sucursal) use ($db, $permitidos): array {
            $almacenes = $db->table('almacenes_inventario')->where('sucursal_id', $sucursal->id)->where('activo', true)
                ->when($permitidos !== null, fn ($query) => $query->whereIn('id', $permitidos))->get(['id']);
            $warehouseIds = $almacenes->pluck('id')->all();
            $items = $warehouseIds === [] ? 0 : $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
                ->whereIn('e.almacen_id', $warehouseIds)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id');
            $estructuras = $warehouseIds !== [] && $db->getSchemaBuilder()->hasTable('farmacia_espacios')
                ? $db->table('farmacia_espacios')->whereIn('almacen_id', $warehouseIds)->get(['tipo'])
                : collect();

            return ['id' => (int) $sucursal->id, 'nombre' => $sucursal->nombre, 'items' => $items, 'almacenes' => $almacenes->count(),
                'estantes' => $estructuras->where('tipo', 'ESTANTE')->count(), 'mostradores' => $estructuras->where('tipo', 'MOSTRADOR')->count(),
                'espacios' => $estructuras->where('tipo', 'ESPACIO')->count()];
        })->values();

        return response()->json(['data' => $sucursales]);
    }

    public function show(int $sucursal, AccesoCompartido $acceso): JsonResponse
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $registroSucursal = $db->table('sucursales_inventario as s')->where('s.id', $sucursal)->where('s.activo', true)
            ->when($permitidos !== null, function ($query) use ($permitidos): void {
                $query->whereExists(function ($subquery) use ($permitidos): void {
                    $subquery->selectRaw('1')->from('almacenes_inventario as a')->whereColumn('a.sucursal_id', 's.id')->where('a.activo', true);
                    if ($permitidos === []) {
                        $subquery->whereRaw('1 = 0');
                    } else {
                        $subquery->whereIn('a.id', $permitidos);
                    }
                });
            })->first(['s.id', 's.nombre']);
        abort_unless($registroSucursal, 404);
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.sucursal_id', $sucursal)->where('a.activo', true)->where('s.activo', true)
            ->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))->orderBy('a.nombre')
            ->get(['a.id', 'a.nombre']);
        $ids = $almacenes->pluck('id')->all();
        $items = $ids === [] ? 0 : $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
            ->whereIn('e.almacen_id', $ids)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id');
        $espacios = $ids !== [] && $db->getSchemaBuilder()->hasTable('farmacia_espacios')
            ? $db->table('farmacia_espacios')->whereIn('almacen_id', $ids)->orderBy('tipo')->orderBy('nombre')->get()
            : collect();
        $repisas = $espacios->isNotEmpty() && $db->getSchemaBuilder()->hasTable('farmacia_repisas')
            ? $db->table('farmacia_repisas')->whereIn('espacio_id', $espacios->pluck('id'))->orderBy('orden')->orderBy('id')->get()->groupBy('espacio_id')
            : collect();
        $porAlmacen = $espacios->groupBy('almacen_id');
        $detalleAlmacenes = $almacenes->map(fn (object $almacen): array => [
            'id' => (int) $almacen->id, 'nombre' => $almacen->nombre,
            'items' => $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')->where('e.almacen_id', $almacen->id)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id'),
            'estructuras' => ($porAlmacen[$almacen->id] ?? collect())->map(fn (object $espacio): array => [
                'id' => (int) $espacio->id, 'tipo' => $espacio->tipo, 'nombre' => $espacio->nombre,
                'repisas' => ($repisas[$espacio->id] ?? collect())->pluck('nombre')->values(),
                'repisaIds' => ($repisas[$espacio->id] ?? collect())->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            ])->values(),
            'productos' => $this->productosAlmacen($db, (int) $almacen->id),
        ]);

        return response()->json(['data' => ['sucursalId' => $sucursal, 'nombre' => $registroSucursal->nombre, 'items' => $items, 'almacenes' => $detalleAlmacenes]]);
    }

    public function asignarItem(int $sucursal, Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $datos = $request->validate([
            'almacen_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'repisa_id' => ['required', 'integer'],
        ]);
        $db = $acceso->conexion();
        $almacenId = (int) $datos['almacen_id'];
        $permitidos = $acceso->almacenesPermitidos();
        abort_unless($db->table('almacenes_inventario')->where('id', $almacenId)->where('sucursal_id', $sucursal)->where('activo', true)->exists(), 404);
        abort_unless($permitidos === null || in_array($almacenId, $permitidos, true), 403);
        $repisa = $db->table('farmacia_repisas as r')->join('farmacia_espacios as e', 'e.id', '=', 'r.espacio_id')->where('r.id', $datos['repisa_id'])->where('e.almacen_id', $almacenId)->first(['e.nombre as estructura', 'r.nombre as repisa']);
        abort_unless($repisa, 422, 'La repisa elegida no pertenece a este almacén.');
        abort_unless($db->table('items_inventario')->where('id', $datos['item_id'])->where('estado', 'ACTIVO')->exists(), 404, 'El producto no está activo en el catálogo.');
        abort_unless($db->table('existencias_lote as x')->join('lotes_items as l', 'l.id', '=', 'x.lote_id')->where('x.almacen_id', $almacenId)->where('l.item_id', $datos['item_id'])->where('x.cantidad', '>', 0)->exists(), 422, 'El producto no tiene existencias en este almacén.');

        $llave = ['item_id' => (int) $datos['item_id'], 'almacen_id' => $almacenId];
        $registro = ['repisa_id' => (int) $datos['repisa_id'], 'ubicacion' => mb_substr($repisa->estructura.' · '.$repisa->repisa, 0, 120), 'updated_at' => now()];
        if ($db->table('item_almacen_ubicaciones')->where($llave)->exists()) {
            $db->table('item_almacen_ubicaciones')->where($llave)->update($registro);
        } else {
            $db->table('item_almacen_ubicaciones')->insert($llave + $registro + ['created_at' => now()]);
        }

        $producto = $this->productosAlmacen($db, $almacenId)->firstWhere('id', (int) $datos['item_id']);

        return response()->json(['message' => 'Ubicación del producto guardada.', 'data' => $producto]);
    }

    private function productosAlmacen($db, int $almacenId)
    {
        return $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')->join('lotes_items as l', 'l.item_id', '=', 'i.id')
            ->join('existencias_lote as x', 'x.lote_id', '=', 'l.id')
            ->leftJoin('item_almacen_ubicaciones as u', function ($join) use ($almacenId): void { $join->on('u.item_id', '=', 'i.id')->where('u.almacen_id', '=', $almacenId); })
            ->where('x.almacen_id', $almacenId)->where('x.cantidad', '>', 0)->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
            ->groupBy('i.id', 'i.clave', 'i.codigo_barras', 'i.nombre_comercial', 'i.marca', 'i.presentacion', 'g.nombre', 'u.repisa_id', 'u.ubicacion')
            ->orderBy('i.nombre_comercial')->get(['i.id', 'i.clave', 'i.codigo_barras', 'i.nombre_comercial as nombre', 'i.marca', 'i.presentacion', 'g.nombre as generico', 'u.repisa_id as repisaId', 'u.ubicacion', $db->raw('SUM(x.cantidad) as cantidad')])
            ->map(fn (object $item): array => ['id' => (int) $item->id, 'codigo' => $item->clave ?: ($item->codigo_barras ?: 'ITM-'.$item->id), 'nombre' => $item->nombre, 'marca' => $item->marca, 'presentacion' => $item->presentacion, 'generico' => $item->generico, 'repisaId' => $item->repisaId === null ? null : (int) $item->repisaId, 'ubicacion' => $item->ubicacion, 'cantidad' => (float) $item->cantidad]);
    }

    public function guardar(int $sucursal, Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $datos = $request->validate([
            'almacen_id' => ['required', 'integer'],
            'estructuras' => ['present', 'array', 'max:100'],
            'estructuras.*.id' => ['nullable', 'integer'],
            'estructuras.*.tipo' => ['required', 'in:ESTANTE,FRIGOBAR,MOSTRADOR,OTRO,ESPACIO'],
            'estructuras.*.nombre' => ['required', 'string', 'max:120'],
            'estructuras.*.repisas' => ['nullable', 'array', 'max:100'],
            'estructuras.*.repisas.*' => ['required', 'string', 'max:120'],
        ]);
        $db = $acceso->conexion();
        $almacenId = (int) $datos['almacen_id'];
        $almacen = $db->table('almacenes_inventario')->where('id', $almacenId)->where('sucursal_id', $sucursal)->where('activo', true)->exists();
        abort_unless($almacen, 404);
        $permitidos = $acceso->almacenesPermitidos();
        abort_unless($permitidos === null || in_array($almacenId, $permitidos, true), 403);

        $db->transaction(function () use ($db, $datos, $almacenId): void {
            $existentes = $db->table('farmacia_espacios')->where('almacen_id', $almacenId)->pluck('id')->all();
            $conservados = [];
            foreach ($datos['estructuras'] as $estructura) {
                $id = $estructura['id'] ?? null;
                abort_if($id && ! in_array((int) $id, $existentes, true), 422, 'Una de las estructuras no pertenece a este almacén.');
                $registro = ['tipo' => $estructura['tipo'], 'nombre' => trim($estructura['nombre']), 'updated_at' => now()];
                if ($id) {
                    $db->table('farmacia_espacios')->where('id', $id)->update($registro);
                } else {
                    $id = $db->table('farmacia_espacios')->insertGetId($registro + ['almacen_id' => $almacenId, 'created_at' => now()]);
                }
                $conservados[] = (int) $id;
                $nombres = collect($estructura['repisas'] ?? [])->map(fn (string $nombre): string => trim($nombre))->filter()->unique()->values();
                $actuales = $db->table('farmacia_repisas')->where('espacio_id', $id)->orderBy('orden')->orderBy('id')->get(['id']);
                foreach ($actuales as $actual) {
                    $db->table('farmacia_repisas')->where('id', $actual->id)->update(['nombre' => '__tmp_repisa_'.$actual->id]);
                }
                foreach ($nombres as $orden => $nombre) {
                    $actual = $actuales->get($orden);
                    if ($actual) {
                        $db->table('farmacia_repisas')->where('id', $actual->id)->update(['nombre' => $nombre, 'orden' => $orden, 'updated_at' => now()]);
                    } else {
                        $db->table('farmacia_repisas')->insert(['espacio_id' => $id, 'nombre' => $nombre, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $repisasEliminadas = $actuales->slice($nombres->count())->pluck('id')->all();
                if ($repisasEliminadas !== []) {
                    $db->table('item_almacen_ubicaciones')->whereIn('repisa_id', $repisasEliminadas)->update(['repisa_id' => null, 'ubicacion' => '']);
                    $db->table('farmacia_repisas')->whereIn('id', $repisasEliminadas)->delete();
                }
            }
            $eliminados = array_diff($existentes, $conservados);
            if ($eliminados !== []) {
                $repisasEliminadas = $db->table('farmacia_repisas')->whereIn('espacio_id', $eliminados)->pluck('id');
                $db->table('item_almacen_ubicaciones')->whereIn('repisa_id', $repisasEliminadas)->update(['repisa_id' => null, 'ubicacion' => '']);
                $db->table('farmacia_espacios')->whereIn('id', $eliminados)->delete();
            }
        });

        return $this->show($sucursal, $acceso);
    }
}
