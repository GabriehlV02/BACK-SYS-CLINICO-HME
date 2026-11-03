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
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('s.activo', true)->where('a.activo', true)->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))
            ->orderBy('s.nombre')->orderBy('a.nombre')->get(['a.id', 'a.nombre as almacen', 'a.sucursal_id', 's.nombre as sucursal']);
        $sucursales = $almacenes->groupBy('sucursal_id')->map(function ($filas, $id) use ($db): array {
            $warehouseIds = $filas->pluck('id')->all();
            $items = $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
                ->whereIn('e.almacen_id', $warehouseIds)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id');
            $espacios = $db->getSchemaBuilder()->hasTable('farmacia_espacios')
                ? $db->table('farmacia_espacios')->whereIn('almacen_id', $warehouseIds)->get()->groupBy('tipo')
                : collect();

            return ['id' => (int) $id, 'nombre' => $filas->first()->sucursal, 'items' => $items, 'almacenes' => $filas->count(),
                'estantes' => $espacios->get('ESTANTE', collect())->count(), 'mostradores' => $espacios->get('MOSTRADOR', collect())->count(),
                'espacios' => $espacios->get('ESPACIO', collect())->count()];
        })->values();

        return response()->json(['data' => $sucursales]);
    }

    public function show(int $sucursal, AccesoCompartido $acceso): JsonResponse
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.sucursal_id', $sucursal)->where('a.activo', true)->where('s.activo', true)
            ->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))->orderBy('a.nombre')
            ->get(['a.id', 'a.nombre']);
        abort_if($almacenes->isEmpty(), 404);
        $ids = $almacenes->pluck('id')->all();
        $items = $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
            ->whereIn('e.almacen_id', $ids)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id');
        $espacios = $db->table('farmacia_espacios')->whereIn('almacen_id', $ids)->orderBy('tipo')->orderBy('nombre')->get();
        $repisas = $db->table('farmacia_repisas')->whereIn('espacio_id', $espacios->pluck('id'))->orderBy('orden')->orderBy('id')->get()->groupBy('espacio_id');
        $porAlmacen = $espacios->groupBy('almacen_id');
        $detalleAlmacenes = $almacenes->map(fn (object $almacen): array => [
            'id' => (int) $almacen->id, 'nombre' => $almacen->nombre,
            'items' => $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')->where('e.almacen_id', $almacen->id)->where('e.cantidad', '>', 0)->distinct()->count('l.item_id'),
            'estructuras' => ($porAlmacen[$almacen->id] ?? collect())->map(fn (object $espacio): array => [
                'id' => (int) $espacio->id, 'tipo' => $espacio->tipo, 'nombre' => $espacio->nombre,
                'repisas' => ($repisas[$espacio->id] ?? collect())->pluck('nombre')->values(),
            ])->values(),
        ]);

        return response()->json(['data' => ['sucursalId' => $sucursal, 'items' => $items, 'almacenes' => $detalleAlmacenes]]);
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
            'estructuras.*.tipo' => ['required', 'in:ESTANTE,MOSTRADOR,ESPACIO'],
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
                $actuales = $db->table('farmacia_repisas')->where('espacio_id', $id)->pluck('nombre');
                $db->table('farmacia_repisas')->where('espacio_id', $id)->whereNotIn('nombre', $nombres->all())->delete();
                foreach ($nombres as $orden => $nombre) {
                    $db->table('farmacia_repisas')->updateOrInsert(['espacio_id' => $id, 'nombre' => $nombre], ['orden' => $orden, 'updated_at' => now(), 'created_at' => now()]);
                }
                $db->table('item_almacen_ubicaciones')->where('repisa_id', '!=', null)->whereIn('repisa_id', $db->table('farmacia_repisas')->where('espacio_id', $id)->whereNotIn('nombre', $nombres->all())->select('id'))->update(['repisa_id' => null]);
            }
            $eliminados = array_diff($existentes, $conservados);
            if ($eliminados !== []) {
                $repisasEliminadas = $db->table('farmacia_repisas')->whereIn('espacio_id', $eliminados)->pluck('id');
                $db->table('item_almacen_ubicaciones')->whereIn('repisa_id', $repisasEliminadas)->update(['repisa_id' => null]);
                $db->table('farmacia_espacios')->whereIn('id', $eliminados)->delete();
            }
        });

        return $this->show($sucursal, $acceso);
    }
}
