<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Atenciones\SincronizarOrdenVenta;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VentaProductosRecepcionController extends Controller
{
    public function autorizar(Request $request, AccesoCompartido $acceso): bool
    {
        $id = $request->user()->id;
        $admin = $acceso->esAdministrador($id);
        $permitido = $acceso->conexion()->table('rol_usuario as ru')
            ->join('roles as r', 'r.id', '=', 'ru.rol_id')
            ->join('rol_sistema as rs', 'rs.rol_id', '=', 'r.id')
            ->join('sistemas as s', 's.id', '=', 'rs.sistema_id')
            ->join('permiso_rol as pr', 'pr.rol_id', '=', 'r.id')
            ->join('permisos as p', 'p.id', '=', 'pr.permiso_id')
            ->where('ru.usuario_id', $id)->where('r.activo', true)
            ->where('s.codigo', 'CONTABLE')->where('s.activo', true)
            ->whereColumn('p.sistema_id', 's.id')->where('p.activo', true)
            ->where('p.codigo', 'VENTAS_REGISTRAR')->exists();
        abort_unless($admin || $permitido, 403, 'No tienes permiso para registrar ventas.');

        return $admin;
    }

    private function cajas(Connection $db, string $id, bool $admin)
    {
        return $db->table('cajas as c')->join('sucursales_inventario as s', 's.id', '=', 'c.sucursal_id')
            ->where('c.activo', true)->where('s.activo', true)
            ->when(! $admin, fn ($q) => $q->whereExists(fn ($q) => $q->selectRaw('1')->from('caja_usuario as cu')->whereColumn('cu.caja_id', 'c.id')->where('cu.usuario_id', $id))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('sucursal_usuario as su')->whereColumn('su.sucursal_id', 's.id')->where('su.usuario_id', $id)));
    }

    private function asignacionesVenta(Connection $db, string $usuarioId)
    {
        return $this->cajas($db, $usuarioId, false)
            ->join('almacen_caja as ac', 'ac.caja_id', '=', 'c.id')
            ->join('almacenes_inventario as a', 'a.id', '=', 'ac.almacen_id')
            ->where('a.activo', true)->whereColumn('a.sucursal_id', 's.id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('almacen_usuario as au')->whereColumn('au.almacen_id', 'a.id')->where('au.usuario_id', $usuarioId))
            ->select('c.id as caja_id', 'a.id as almacen_id')->distinct()->get();
    }

    public function opciones(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $admin = $this->autorizar($request, $acceso);
        $db = $acceso->conexion();
        $id = $request->user()->id;
        $cajas = $this->cajas($db, $id, $admin)->orderBy('s.nombre')->orderBy('c.nombre')
            ->get(['c.id', 'c.nombre', 'c.codigo', 'c.sucursal_id', 's.nombre as sucursal']);
        $almacenes = $db->table('almacen_caja as ac')->join('almacenes_inventario as a', 'a.id', '=', 'ac.almacen_id')
            ->whereIn('ac.caja_id', $cajas->pluck('id'))->where('a.activo', true)
            ->when(! $admin, fn ($q) => $q->whereExists(fn ($q) => $q->selectRaw('1')->from('almacen_usuario as au')->whereColumn('au.almacen_id', 'a.id')->where('au.usuario_id', $id)))
            ->orderBy('a.nombre')->get(['ac.caja_id', 'a.id', 'a.nombre']);

        $asignaciones = $this->asignacionesVenta($db, $id);

        return response()->json(['data' => $cajas->map(fn ($caja) => ['id' => $caja->id, 'nombre' => $caja->nombre, 'codigo' => $caja->codigo, 'sucursal_id' => $caja->sucursal_id, 'sucursal' => $caja->sucursal, 'almacenes' => $almacenes->where('caja_id', $caja->id)->values()]), 'asignacion_usuario' => $asignaciones->count() === 1 ? $asignaciones->first() : null, 'cantidad_asignaciones' => $asignaciones->count()]);
    }

    public function historial(Request $request, string $cliente, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $acceso);
        $db = $acceso->conexion();
        abort_unless($db->table('clientes')->where('id', $cliente)->exists(), 404);
        $ventas = $db->table('carritos_venta as v')
            ->leftJoin('sucursales_inventario as s', 's.id', '=', 'v.sucursal_id')
            ->leftJoin('cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('usuarios_sistema as u', 'u.id', '=', 'v.usuario_sistema_id')
            ->leftJoin('ventas_clinicas_solicitudes as vs', 'vs.venta_id', '=', 'v.id')
            ->where('v.cliente_id', $cliente)
            ->whereIn('v.estado', ['COMPLETADA', 'ANULADA', 'BORRADOR'])
            ->orderByDesc('v.created_at')->orderByDesc('v.id')
            ->get(['v.id', 'v.codigo', 'v.estado', 'v.total', 'v.cobrada_en', 'v.created_at', 's.nombre as sucursal', 'c.nombre as caja', 'u.nombres as cajero_nombres', 'u.apellidos as cajero_apellidos', 'vs.pagos', 'vs.cambio']);
        $detalles = $db->table('carrito_venta_detalles')->whereIn('carrito_venta_id', $ventas->pluck('id'))
            ->orderBy('created_at')->orderBy('id')->get(['carrito_venta_id', 'nombre_item', 'cantidad', 'precio_unitario', 'total'])->groupBy('carrito_venta_id');

        return response()->json(['data' => $ventas->map(function ($venta) use ($detalles): array {
            $pagos = $venta->pagos ? json_decode($venta->pagos, true, 512, JSON_THROW_ON_ERROR) : null;

            return [
                'id' => $venta->id, 'codigo' => $venta->codigo, 'estado' => $venta->estado, 'total' => $venta->total,
                'fecha' => $venta->cobrada_en ?? $venta->created_at, 'sucursal' => $venta->sucursal, 'caja' => $venta->caja,
                'cajero' => trim(($venta->cajero_nombres ?? '').' '.($venta->cajero_apellidos ?? '')),
                'pagos' => $pagos, 'cambio' => $venta->cambio,
                'detalles' => $detalles->get($venta->id, collect())->values(),
            ];
        })]);
    }

    public function store(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $admin = $this->autorizar($request, $acceso);
        if ($request->boolean('usar_asignacion_usuario')) {
            $asignaciones = $this->asignacionesVenta($acceso->conexion(), $request->user()->id);
            if ($asignaciones->count() !== 1) {
                throw ValidationException::withMessages(['caja_id' => 'El usuario debe tener una sola caja y almacén asignados para este cobro.']);
            }
            $request->merge(['caja_id' => $asignaciones->first()->caja_id, 'almacen_id' => $asignaciones->first()->almacen_id]);
        }
        $datos = $request->validate([
            'solicitud_id' => ['required', 'uuid'], 'cliente_id' => ['required', 'uuid'],
            'caja_id' => ['required', 'integer'], 'almacen_id' => ['required', 'integer'],
            'descuento' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.item_id' => ['required_without:detalles.*.servicio_id', 'integer', 'distinct'],
            'detalles.*.servicio_id' => ['required_without:detalles.*.item_id', 'integer', 'distinct'],
            'detalles.*.cita_id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'detalles.*.precio_unitario' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'pagos' => ['required', 'array', 'min:1', 'max:10'],
            'pagos.*.metodo' => ['required', Rule::in(['Efectivo', 'QR', 'Tarjeta', 'Transferencia'])],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);
        foreach ($datos['detalles'] as $linea) {
            if (isset($linea['item_id'], $linea['servicio_id'])) {
                throw ValidationException::withMessages(['detalles' => 'Cada línea debe corresponder a un producto o a un servicio.']);
            }
        }
        $db = $acceso->conexion();
        $usuarioId = $request->user()->id;
        $huella = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
        $venta = $db->transaction(function () use ($db, $datos, $usuarioId, $admin, $huella) {
            $db->table('usuarios_sistema')->where('id', $usuarioId)->lockForUpdate()->first();
            $existente = $db->table('ventas_clinicas_solicitudes')->where('solicitud_id', $datos['solicitud_id'])->first();
            if ($existente) {
                abort_unless($existente->usuario_id === $usuarioId && hash_equals($existente->huella, $huella), 409, 'Esta solicitud ya fue utilizada con otros datos.');

                return $db->table('carritos_venta')->where('id', $existente->venta_id)->first();
            }
            $caja = $this->cajas($db, $usuarioId, $admin)->where('c.id', $datos['caja_id'])->first(['c.id', 'c.sucursal_id', 'c.tipos_item']);
            abort_unless($caja, 403, 'No tienes acceso a esta caja o sucursal.');
            $almacen = $db->table('almacen_caja as ac')->join('almacenes_inventario as a', 'a.id', '=', 'ac.almacen_id')
                ->where('ac.caja_id', $caja->id)->where('a.id', $datos['almacen_id'])->where('a.activo', true)->where('a.sucursal_id', $caja->sucursal_id)
                ->when(! $admin, fn ($q) => $q->whereExists(fn ($q) => $q->selectRaw('1')->from('almacen_usuario as au')->whereColumn('au.almacen_id', 'a.id')->where('au.usuario_id', $usuarioId)))->first();
            abort_unless($almacen, 403, 'No tienes acceso al almacén seleccionado.');
            abort_unless($db->table('clientes')->where('id', $datos['cliente_id'])->exists(), 422, 'Selecciona un paciente vinculado al contable.');
            $lineas = collect($datos['detalles'])->sortBy(fn ($linea) => ($linea['servicio_id'] ?? 0).':'.($linea['item_id'] ?? 0))->values();
            $servicioIds = $lineas->pluck('servicio_id')->filter()->values();
            $servicios = $servicioIds->isEmpty() ? collect() : $db->table('servicios as s')->join('tipos_inventario as t', 't.id', '=', 's.tipo_id')
                ->leftJoin('categorias_inventario as c', 'c.id', '=', 's.categoria_id')
                ->whereIn('s.id', $servicioIds)->where('s.estado', 'ACTIVO')->where('t.activo', true)->where('t.tipo_base', 'SERVICIO')
                ->get(['s.id', 's.nombre', 's.precio_venta', 't.nombre as tipo_servicio', 'c.nombre as categoria'])->keyBy('id');
            $pacienteId = null;
            if ($servicioIds->isNotEmpty()) {
                $pacienteId = $db->table('clientes')->where('id', $datos['cliente_id'])->value('paciente_id');
                abort_unless($pacienteId && \Illuminate\Support\Facades\DB::table('pacientes')->where('id', $pacienteId)->exists(), 422, 'Selecciona un paciente clínico vinculado al cliente.');
            }
            $items = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')
                ->whereIn('i.id', $lineas->pluck('item_id'))->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->where('t.activo', true)->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
                ->get(['i.id', 'i.nombre_comercial', 'i.precio_1', 't.tipo_base'])->keyBy('id');
            $subtotal = 0;
            foreach ($lineas as $linea) {
                if (isset($linea['servicio_id'])) {
                    $servicio = $servicios->get($linea['servicio_id']);
                    $esConsulta = $servicio && str_contains(strtoupper(Str::ascii($servicio->tipo_servicio)), 'CONSULTA');
                    if ($esConsulta) {
                        if (empty($linea['cita_id']) || (int) $linea['cantidad'] !== 1) {
                            throw ValidationException::withMessages(['detalles' => 'Selecciona una consulta agendada por cada servicio de consulta.']);
                        }
                        $cita = \Illuminate\Support\Facades\DB::table('citas')->where('id', $linea['cita_id'])->where('paciente_id', $pacienteId)
                            ->where('servicio_id', $servicio->id)->where('estado', 'por_confirmar')->first();
                        if (! $cita || $db->table('ventas_clinicas_ordenes')->where('cita_id', $linea['cita_id'])->exists()) {
                            throw ValidationException::withMessages(['detalles' => 'La consulta no esta pendiente, no corresponde al paciente o ya tiene una venta.']);
                        }
                    } elseif (! empty($linea['cita_id'])) {
                        throw ValidationException::withMessages(['detalles' => 'La cita solo puede asociarse a un servicio de consulta.']);
                    }
                    if (! $servicio || (! $esConsulta && ! $this->tipoEstudio($servicio)) || $linea['cantidad'] > 100) {
                        throw ValidationException::withMessages(['detalles' => 'Selecciona un servicio de imagenología activo y reconocido.']);
                    }
                    if (! in_array('Servicios', json_decode($caja->tipos_item, true) ?? [], true)) {
                        throw ValidationException::withMessages(['caja_id' => 'La caja seleccionada no permite vender servicios.']);
                    }
                    if ((int) round((float) $servicio->precio_venta * 100) !== (int) round((float) $linea['precio_unitario'] * 100)) {
                        throw ValidationException::withMessages(['detalles' => 'El precio del servicio cambió. Actualiza el catálogo.']);
                    }
                    $subtotal += (int) round((float) $servicio->precio_venta * 100) * $linea['cantidad'];
                    continue;
                }
                $item = $items->get($linea['item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['detalles' => 'Uno de los productos o insumos no está activo.']);
                }
                $tipoPermitido = $item->tipo_base === 'INSUMO' ? 'Insumos' : 'Productos';
                if (! in_array($tipoPermitido, json_decode($caja->tipos_item, true) ?? [], true)) {
                    throw ValidationException::withMessages(['caja_id' => 'La caja seleccionada no permite vender '.mb_strtolower($tipoPermitido).'.']);
                }
                if (! in_array($tipoPermitido, json_decode($almacen->tipos_item, true) ?? [], true)) {
                    throw ValidationException::withMessages(['almacen_id' => 'El almacén seleccionado no permite vender '.mb_strtolower($tipoPermitido).'.']);
                }
                if ((int) round((float) $item->precio_1 * 100) !== (int) round((float) $linea['precio_unitario'] * 100)) {
                    throw ValidationException::withMessages(['detalles' => 'El precio de '.$item->nombre_comercial.' cambió. Actualiza el catálogo y el carrito.']);
                }
                $subtotal += (int) round((float) $item->precio_1 * 100) * $linea['cantidad'];
            }
            $descuento = (int) round((float) $datos['descuento'] * 100);
            if ($descuento > $subtotal) {
                throw ValidationException::withMessages(['descuento' => 'El descuento supera el total.']);
            }
            $pagado = collect($datos['pagos'])->sum(fn ($p) => (int) round((float) $p['monto'] * 100));
            if ($pagado < $subtotal - $descuento) {
                throw ValidationException::withMessages(['pagos' => 'El pago no cubre el total.']);
            }
            $efectivo = collect($datos['pagos'])->where('metodo', 'Efectivo')->sum(fn ($p) => (int) round((float) $p['monto'] * 100));
            if ($pagado - ($subtotal - $descuento) > $efectivo) {
                throw ValidationException::withMessages(['pagos' => 'El cambio no puede superar el efectivo recibido.']);
            }
            $codigo = 'VTA-'.str_pad((string) ($db->table('carritos_venta')->where('usuario_sistema_id', $usuarioId)->count() + 1), 8, '0', STR_PAD_LEFT);
            while ($db->table('carritos_venta')->where('usuario_sistema_id', $usuarioId)->where('codigo', $codigo)->exists()) {
                $codigo = 'VTA-'.str_pad((string) ((int) substr($codigo, 4) + 1), 8, '0', STR_PAD_LEFT);
            }
            $ventaId = (string) Str::uuid();
            $ahora = now();
            $db->table('carritos_venta')->insert(['id' => $ventaId, 'codigo' => $codigo, 'cliente_id' => $datos['cliente_id'], 'usuario_sistema_id' => $usuarioId, 'sucursal_id' => $caja->sucursal_id, 'caja_id' => $caja->id, 'estado' => 'COMPLETADA', 'moneda' => 'BOB', 'subtotal' => $subtotal / 100, 'descuento' => $descuento / 100, 'total' => ($subtotal - $descuento) / 100, 'observaciones' => 'Origen: recepción clínica', 'cobrada_en' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora]);
            foreach ($lineas as $linea) {
                if (isset($linea['servicio_id'])) {
                    $servicio = $servicios->get($linea['servicio_id']);
                    $detalleId = (string) Str::uuid();
                    $db->table('carrito_venta_detalles')->insert(['id' => $detalleId, 'carrito_venta_id' => $ventaId, 'codigo_item' => 'SRV-'.str_pad((string) $servicio->id, 6, '0', STR_PAD_LEFT), 'nombre_item' => $servicio->nombre, 'tipo_item' => 'Servicio', 'cantidad' => $linea['cantidad'], 'precio_unitario' => $servicio->precio_venta, 'descuento' => 0, 'total' => round((float) $servicio->precio_venta * $linea['cantidad'], 2), 'created_at' => $ahora, 'updated_at' => $ahora]);
                    $tipoOrden = ! empty($linea['cita_id']) ? 'CONSULTA' : $this->tipoEstudio($servicio);
                    for ($unidad = 1; $unidad <= $linea['cantidad']; $unidad++) {
                        $db->table('ventas_clinicas_ordenes')->insert(['venta_detalle_id' => $detalleId, 'unidad' => $unidad, 'paciente_id' => $pacienteId, 'tipo' => $tipoOrden, 'cita_id' => $linea['cita_id'] ?? null, 'nombre' => $servicio->nombre, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    }
                    continue;
                }
                $item = $items->get($linea['item_id']);
                $restante = $linea['cantidad'] * 1000;
                $existencias = $db->table('existencias_lote as e')->join('lotes_items as l', 'l.id', '=', 'e.lote_id')
                    ->where('e.almacen_id', $almacen->id)->where('l.item_id', $item->id)->where('e.cantidad', '>', 0)
                    ->where(fn ($q) => $q->whereNull('l.vencimiento')->orWhereDate('l.vencimiento', '>=', now()->toDateString()))
                    ->orderByRaw('CASE WHEN l.vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('l.vencimiento')->orderBy('e.id')->lockForUpdate()->get(['e.id', 'e.lote_id', 'e.cantidad']);
                foreach ($existencias as $existencia) {
                    $salida = min($restante, (int) round((float) $existencia->cantidad * 1000));
                    if ($salida <= 0) {
                        continue;
                    }
                    $db->table('existencias_lote')->where('id', $existencia->id)->decrement('cantidad', $salida / 1000);
                    $db->table('ventas_clinicas_salidas')->insert(['venta_id' => $ventaId, 'lote_id' => $existencia->lote_id, 'almacen_id' => $almacen->id, 'cantidad' => $salida / 1000, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    $restante -= $salida;
                    if (! $restante) {
                        break;
                    }
                }
                if ($restante) {
                    throw ValidationException::withMessages(['detalles' => 'Stock insuficiente o vencido para '.$item->nombre_comercial.'.']);
                }
                $db->table('carrito_venta_detalles')->insert(['id' => (string) Str::uuid(), 'carrito_venta_id' => $ventaId, 'codigo_item' => 'ITM-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT), 'nombre_item' => $item->nombre_comercial, 'tipo_item' => $item->tipo_base === 'INSUMO' ? 'Insumo' : 'Producto', 'cantidad' => $linea['cantidad'], 'precio_unitario' => $item->precio_1, 'descuento' => 0, 'total' => round((float) $item->precio_1 * $linea['cantidad'], 2), 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            $db->table('ventas_clinicas_solicitudes')->insert(['solicitud_id' => $datos['solicitud_id'], 'usuario_id' => $usuarioId, 'venta_id' => $ventaId, 'huella' => $huella, 'pagos' => json_encode($datos['pagos'], JSON_THROW_ON_ERROR), 'cambio' => ($pagado - ($subtotal - $descuento)) / 100, 'created_at' => $ahora, 'updated_at' => $ahora]);

            return $db->table('carritos_venta')->where('id', $ventaId)->first();
        }, 3);

        $pendiente = false;
        if (collect($datos['detalles'])->contains(fn ($linea) => isset($linea['servicio_id']))) {
            try {
                app(SincronizarOrdenVenta::class)->procesar($db, $venta->id);
            } catch (\Throwable $error) {
                report($error);
                $pendiente = true;
            }
        }
        return response()->json(['data' => $venta, 'ordenes_pendientes' => $pendiente], 201);
    }

    private function tipoEstudio(object $servicio): ?string
    {
        $texto = strtoupper(Str::ascii(implode(' ', [$servicio->tipo_servicio ?? '', $servicio->categoria ?? '', $servicio->nombre])));
        return match (true) {
            str_contains($texto, 'ECOCARDIO') => 'ECOCARDIOGRAMA',
            str_contains($texto, 'TOMOGRAF') => 'TOMOGRAFIA',
            str_contains($texto, 'ECOGRAF') => 'ECOGRAFIA',
            str_contains($texto, 'RADIOGRAF'), str_contains($texto, 'RAYOS X') => 'RADIOGRAFIA',
            default => null,
        };
    }

    public function recuperarOrdenes(Request $request, AccesoCompartido $acceso, SincronizarOrdenVenta $sincronizador): JsonResponse
    {
        $admin = $this->autorizar($request, $acceso);
        $db = $acceso->conexion();
        $ventas = $db->table('ventas_clinicas_ordenes as o')
            ->join('carrito_venta_detalles as d', 'd.id', '=', 'o.venta_detalle_id')
            ->join('carritos_venta as v', 'v.id', '=', 'd.carrito_venta_id')
            ->when(! $admin, fn ($query) => $query->where('v.usuario_sistema_id', $request->user()->id))->where('v.estado', 'COMPLETADA')
            ->whereNull('o.procesada_en')->distinct()->limit(25)->pluck('v.id');
        $errores = 0;
        foreach ($ventas as $ventaId) {
            try {
                $sincronizador->procesar($db, $ventaId);
            } catch (\Throwable $error) {
                report($error);
                $errores++;
            }
        }
        return response()->json(['ventas_revisadas' => $ventas->count(), 'pendientes' => $errores]);
    }

    public function consultarSolicitud(Request $request, string $solicitud, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $acceso);
        $venta = $acceso->conexion()->table('ventas_clinicas_solicitudes as s')
            ->join('carritos_venta as v', 'v.id', '=', 's.venta_id')
            ->where('s.solicitud_id', $solicitud)->where('s.usuario_id', $request->user()->id)
            ->first(['v.id', 'v.codigo', 'v.estado']);
        abort_unless($venta, 404);
        return response()->json(['data' => $venta]);
    }
}
