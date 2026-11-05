<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Farmacia\CatalogoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmaciaAlmacenController
{
    public function index(CatalogoCompartido $catalogo): JsonResponse
    {
        return response()->json(['data' => array_values(array_filter($catalogo->obtener(), fn (array $item): bool => $item['tipo'] !== 'servicio'))]);
    }

    public function existencias(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario && $acceso->esAdministrador($usuario->id), 403, 'Solo un administrador puede consultar almacenes de varias ubicaciones.');

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer', 'min:1'],
            'almacenes' => ['required', 'array', 'min:1', 'max:50'],
            'almacenes.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        $db = $acceso->conexion();
        $sucursal = $db->table('sucursales_inventario')->where('id', $datos['sucursal_id'])->where('activo', true)->first(['id', 'nombre']);
        abort_unless($sucursal, 404, 'La sucursal no existe o está inactiva.');

        $almacenesSolicitados = array_map('intval', $datos['almacenes']);
        $almacenesValidos = $db->table('almacenes_inventario')->where('sucursal_id', $sucursal->id)->where('activo', true)
            ->whereIn('id', $almacenesSolicitados)->orderBy('nombre')->get(['id', 'nombre']);
        abort_if($almacenesValidos->count() !== count($almacenesSolicitados), 422, 'Selecciona almacenes activos que pertenezcan a la sucursal elegida.');

        $ids = $almacenesValidos->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $items = $db->table('items_inventario as i')
            ->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')
            ->join('unidades_inventario as u', 'u.id', '=', 'i.unidad_id')
            ->join('lotes_items as l', 'l.item_id', '=', 'i.id')
            ->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
            ->whereIn('e.almacen_id', $ids)->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')
            ->where('t.activo', true)->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
            ->groupBy('i.id', 'i.nombre_comercial', 'i.clave', 'i.codigo_barras', 'u.nombre')
            ->orderBy('i.nombre_comercial')->orderBy('i.id')
            ->select(['i.id', 'i.nombre_comercial as nombre', 'i.clave', 'i.codigo_barras', 'u.nombre as unidad'])
            ->selectRaw('SUM(e.cantidad) as disponible')->get()
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'nombre' => $item->nombre,
                'codigo' => $item->codigo_barras ?: ($item->clave ?: 'ITM-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT)),
                'unidad' => $item->unidad,
                'disponible' => (float) $item->disponible,
                'minimo' => null,
            ]);

        return response()->json([
            'sucursal' => ['id' => (int) $sucursal->id, 'nombre' => $sucursal->nombre],
            'almacenes' => $almacenesValidos->map(fn (object $almacen): array => ['id' => (int) $almacen->id, 'nombre' => $almacen->nombre])->values(),
            'data' => $items->values(),
        ]);
    }

    public function store(): JsonResponse
    {
        return response()->json(['message' => 'Registra productos, lotes e ingresos en el sistema contable. El inventario de farmacia es compartido.'], 409);
    }
}
