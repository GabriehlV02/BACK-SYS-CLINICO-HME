<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuditoriaVentasController
{
    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        $acceso->exigir($usuario->id, 'VENTAS_VER');
        $filtros = $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'sucursal_id' => ['nullable', 'integer', 'min:1'],
            'caja_id' => ['nullable', 'integer', 'min:1'],
            'usuario_id' => ['nullable', 'uuid'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if (! empty($filtros['fecha_desde']) && ! empty($filtros['fecha_hasta']) && $filtros['fecha_desde'] > $filtros['fecha_hasta']) {
            throw ValidationException::withMessages(['fecha_hasta' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }

        $db = $acceso->conexion();
        $esAdministrador = $db->table('rol_usuario as ru')->join('roles as r', 'r.id', '=', 'ru.rol_id')
            ->join('rol_sistema as rs', 'rs.rol_id', '=', 'r.id')->join('sistemas as s', 's.id', '=', 'rs.sistema_id')
            ->where('ru.usuario_id', $usuario->id)->where('r.codigo', 'ADMINISTRADOR')->where('r.activo', true)
            ->where('s.codigo', 'CONTABLE')->where('s.activo', true)->exists();
        $sucursalesPermitidas = $esAdministrador ? null : $db->table('sucursal_usuario')->where('usuario_id', $usuario->id)->pluck('sucursal_id')->all();
        $consulta = $db->table('carritos_venta as v')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->leftJoin('sucursales_inventario as s', 's.id', '=', 'v.sucursal_id')
            ->leftJoin('cajas as ca', 'ca.id', '=', 'v.caja_id')
            ->leftJoin('usuarios_sistema as u', 'u.id', '=', 'v.usuario_sistema_id')
            ->where('v.estado', 'COMPLETADA')
            ->when($sucursalesPermitidas !== null, fn (Builder $q) => $q->where(fn (Builder $alcance) => $alcance->whereIn('v.sucursal_id', $sucursalesPermitidas)->orWhere(fn (Builder $legacy) => $legacy->whereNull('v.sucursal_id')->where('v.usuario_sistema_id', $usuario->id))))
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '>=', $fecha))
            ->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '<=', $fecha))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int $id) => $q->where('v.sucursal_id', $id))
            ->when($filtros['caja_id'] ?? null, fn (Builder $q, int $id) => $q->where('v.caja_id', $id))
            ->when($filtros['usuario_id'] ?? null, fn (Builder $q, string $id) => $q->where('v.usuario_sistema_id', $id))
            ->when(trim($filtros['buscar'] ?? '') !== '', function (Builder $q) use ($filtros): void {
                $termino = '%'.trim($filtros['buscar']).'%';
                $q->where(fn (Builder $buscar) => $buscar->where('v.codigo', 'like', $termino)
                    ->orWhere('c.nombre_razon_social', 'like', $termino)
                    ->orWhere('ca.nombre', 'like', $termino)
                    ->orWhere('s.nombre', 'like', $termino)
                    ->orWhere('u.nombres', 'like', $termino)
                    ->orWhere('u.apellidos', 'like', $termino)
                    ->orWhere('u.usuario', 'like', $termino));
            });

        $sumatoria = (clone $consulta)->sum('v.total');
        $ventas = (clone $consulta)->orderByDesc('v.cobrada_en')->orderByDesc('v.id')
            ->paginate($filtros['per_page'] ?? 50, [
                'v.id', 'v.codigo', 'v.cobrada_en', 'v.total', 'v.moneda', 'v.observaciones',
                'v.sucursal_id', 's.nombre as sucursal', 'v.caja_id', 'ca.nombre as caja', 'ca.codigo as caja_codigo',
                'v.usuario_sistema_id as usuario_id', 'u.nombres as usuario_nombres', 'u.apellidos as usuario_apellidos', 'u.usuario',
                'c.nombre_razon_social as cliente',
            ], 'page', $filtros['page'] ?? 1);

        $sucursales = $db->table('sucursales_inventario as s')->join('carritos_venta as v', 'v.sucursal_id', '=', 's.id')
            ->where('v.estado', 'COMPLETADA')->when($sucursalesPermitidas !== null, fn (Builder $q) => $q->whereIn('v.sucursal_id', $sucursalesPermitidas))
            ->select('s.id', 's.nombre')->distinct()->orderBy('s.nombre')->get();
        $cajas = $db->table('cajas as c')->join('carritos_venta as v', 'v.caja_id', '=', 'c.id')->where('v.estado', 'COMPLETADA')
            ->when($sucursalesPermitidas !== null, fn (Builder $q) => $q->whereIn('v.sucursal_id', $sucursalesPermitidas))
            ->select('c.id', 'c.nombre', 'c.codigo', 'c.sucursal_id')->distinct()->orderBy('c.nombre')->get();
        $usuarios = $db->table('usuarios_sistema as u')->join('carritos_venta as v', 'v.usuario_sistema_id', '=', 'u.id')->where('v.estado', 'COMPLETADA')
            ->when($sucursalesPermitidas !== null, fn (Builder $q) => $q->whereIn('v.sucursal_id', $sucursalesPermitidas))
            ->select('u.id', 'u.nombres', 'u.apellidos', 'u.usuario')->distinct()->orderBy('u.nombres')->get();

        return response()->json([
            'data' => $ventas->items(), 'current_page' => $ventas->currentPage(), 'last_page' => $ventas->lastPage(),
            'total' => $ventas->total(), 'total_ventas' => (float) $sumatoria,
            'sucursales' => $sucursales, 'cajas' => $cajas, 'usuarios' => $usuarios,
        ])->header('Cache-Control', 'no-store');
    }
}
