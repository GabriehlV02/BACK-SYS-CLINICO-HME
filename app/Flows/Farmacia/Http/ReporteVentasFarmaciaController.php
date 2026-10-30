<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReporteVentasFarmaciaController
{
    public function __invoke(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate([
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            'fecha_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
        ]);
        $db = $acceso->conexion();
        $autorizado = $db->table('rol_usuario as ru')
            ->join('roles as r', 'r.id', '=', 'ru.rol_id')
            ->join('rol_sistema as rs', 'rs.rol_id', '=', 'r.id')
            ->join('sistemas as s', 's.id', '=', 'rs.sistema_id')
            ->join('permiso_rol as pr', 'pr.rol_id', '=', 'r.id')
            ->join('permisos as p', 'p.id', '=', 'pr.permiso_id')
            ->where('ru.usuario_id', $request->user()->id)
            ->where('r.activo', true)->where('s.activo', true)->where('s.codigo', 'CONTABLE')
            ->whereColumn('p.sistema_id', 's.id')->where('p.activo', true)->where('p.codigo', 'VENTAS_VER')
            ->exists();
        abort_unless($autorizado, 403, 'No tienes permiso para consultar las ventas.');

        $ventas = $db->table('carritos_venta as v')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->where('v.estado', 'COMPLETADA')->where('v.observaciones', 'Origen: farmacia')
            ->whereDate('v.cobrada_en', '>=', $datos['fecha_desde'])
            ->whereDate('v.cobrada_en', '<=', $datos['fecha_hasta'])
            ->orderByDesc('v.cobrada_en')
            ->get(['v.id', 'v.codigo', 'v.cobrada_en', 'v.total', 'v.observaciones', 'c.nombre_razon_social']);
        $detalles = $db->table('carrito_venta_detalles')
            ->whereIn('carrito_venta_id', $ventas->pluck('id'))
            ->get(['id', 'carrito_venta_id', 'codigo_item', 'nombre_item', 'cantidad', 'total'])
            ->groupBy('carrito_venta_id');

        return response()->json(['data' => $ventas->map(fn (object $venta): array => [
            'id' => $venta->id,
            'codigo' => $venta->codigo,
            'cobrada_en' => Carbon::parse($venta->cobrada_en, config('app.timezone'))->toIso8601String(),
            'total' => $venta->total,
            'observaciones' => $venta->observaciones,
            'cliente' => ['nombre_razon_social' => $venta->nombre_razon_social],
            'detalles' => $detalles->get($venta->id, collect())->values(),
        ])->values()])->header('Cache-Control', 'no-store');
    }
}
