<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InsumosEnfermeriaController extends Controller
{
    public function __construct(private AccesoCompartido $acceso) {}

    private function almacenes(): Builder
    {
        $ids = $this->acceso->almacenesPermitidos();

        return $this->acceso->conexion()->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.activo', true)->where('s.activo', true)->when($ids !== null, fn ($q) => $q->whereIn('a.id', $ids));
    }

    private function exigirAlmacen(int $id): void
    {
        abort_unless($this->almacenes()->where('a.id', $id)->exists(), 403, 'No tienes acceso al almacén seleccionado.');
    }

    private function catalogo(): Builder
    {
        return $this->acceso->conexion()->table('items_inventario as i')->join('productos_base as p', 'p.id', '=', 'i.producto_base_id')
            ->join('tipos_inventario as t', 't.id', '=', 'p.tipo_id')->join('unidades_inventario as u', 'u.id', '=', 'i.unidad_id')
            ->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])->where('t.activo', true)->where('u.activo', true);
    }

    public function opciones(): JsonResponse
    {
        return response()->json([
            'almacenes' => $this->almacenes()->orderBy('s.nombre')->orderBy('a.nombre')->get(['a.id', 'a.nombre', 's.nombre as sucursal']),
            'catalogo' => $this->catalogo()->where('i.estado', 'ACTIVO')->where('p.estado', 'ACTIVO')->orderBy('i.nombre_comercial')
                ->get(['i.id', 'i.nombre_comercial as nombre', 'i.clave as codigo', 'u.nombre as unidad']),
        ]);
    }

    public function stock(Request $request): JsonResponse
    {
        $datos = $request->validate(['almacen_id' => ['required', 'integer', 'min:1']]);
        $this->exigirAlmacen((int) $datos['almacen_id']);
        $stock = $this->acceso->conexion()->table('existencias_lote as e')->join('lotes_items as l', 'l.id', '=', 'e.lote_id')
            ->where('e.almacen_id', $datos['almacen_id'])->selectRaw('l.item_id, SUM(e.cantidad) as cantidad')->groupBy('l.item_id');

        return response()->json(['data' => $this->catalogo()->joinSub($stock, 'stock', 'stock.item_id', '=', 'i.id')
            ->orderBy('i.nombre_comercial')->get(['i.id', 'i.nombre_comercial as nombre', 'i.clave as codigo', 'u.nombre as unidad', 'i.estado', 'p.estado as estado_producto', 'stock.cantidad'])]);
    }

    private function exigirFarmacia(Request $request): void
    {
        if (! $this->acceso->esAdministrador($request->user()->id)) {
            $this->acceso->exigir($request->user()->id, 'INVENTARIO_GESTIONAR');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $consulta = $this->acceso->conexion()->table('clinico_solicitudes_insumos as r')->join('almacenes_inventario as a', 'a.id', '=', 'r.almacen_destino_id');
        if ($request->boolean('farmacia')) {
            $this->exigirFarmacia($request);
        } else {
            $consulta->whereIn('r.almacen_destino_id', $this->almacenes()->pluck('a.id')->all());
        }

        return response()->json(['data' => $consulta->orderByDesc('r.created_at')->limit(200)->get(['r.*', 'a.nombre as almacen'])->map(function (object $r): object {
            $r->detalles = json_decode($r->detalles, true, 512, JSON_THROW_ON_ERROR);
            unset($r->movimientos);

            return $r;
        })]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'id' => ['required', 'uuid'], 'almacen_destino_id' => ['required', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:1000'], 'detalles' => ['required', 'array', 'min:1', 'max:30'],
            'detalles.*' => ['array:item_id,cantidad'], 'detalles.*.item_id' => ['required', 'integer', 'distinct', 'min:1'],
            'detalles.*.cantidad' => ['required', 'numeric', 'min:0.001', 'max:100000', 'decimal:0,3'],
        ]);
        $this->exigirAlmacen((int) $datos['almacen_destino_id']);
        $detalles = [];
        foreach ($datos['detalles'] as $detalle) {
            $item = $this->catalogo()->where('i.id', $detalle['item_id'])->where('i.estado', 'ACTIVO')->where('p.estado', 'ACTIVO')->first(['i.id', 'i.nombre_comercial as nombre', 'u.nombre as unidad']);
            if (! $item) {
                throw ValidationException::withMessages(['detalles' => 'Selecciona insumos activos del catálogo.']);
            }
            $detalles[] = ['item_id' => (int) $item->id, 'nombre' => $item->nombre, 'unidad' => $item->unidad, 'cantidad' => (float) $detalle['cantidad']];
        }
        $db = $this->acceso->conexion();
        $db->transaction(function () use ($db, $request, $datos, $detalles): void {
            $db->table('almacenes_inventario')->where('id', $datos['almacen_destino_id'])->lockForUpdate()->first();
            $existente = $db->table('clinico_solicitudes_insumos')->where('id', $datos['id'])->first();
            if ($existente) {
                abort_unless($existente->solicitado_por === $request->user()->id && (int) $existente->almacen_destino_id === (int) $datos['almacen_destino_id'] && json_decode($existente->detalles, true) == $detalles, 409, 'La solicitud ya existe con otros datos.');

                return;
            }
            $usuario = $request->user();
            $db->table('clinico_solicitudes_insumos')->insert([
                'id' => $datos['id'], 'solicitado_por' => $usuario->id, 'solicitante' => trim(($usuario->nombres ?? $usuario->nombre ?? '').' '.($usuario->apellidos ?? '')),
                'almacen_destino_id' => $datos['almacen_destino_id'], 'estado' => 'pendiente', 'detalles' => json_encode($detalles, JSON_THROW_ON_ERROR),
                'observacion' => $datos['observacion'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['id' => $datos['id'], 'message' => 'Solicitud enviada a Farmacia. El stock se actualizará cuando sea dispensada.'], 201);
    }

    public function dispensar(Request $request, string $solicitud): JsonResponse
    {
        $this->exigirFarmacia($request);
        $datos = $request->validate(['almacen_origen_id' => ['required', 'integer', 'min:1']]);
        $origen = (int) $datos['almacen_origen_id'];
        $this->exigirAlmacen($origen);
        $db = $this->acceso->conexion();
        $db->transaction(function () use ($db, $request, $solicitud, $origen): void {
            $pedido = $db->table('clinico_solicitudes_insumos')->where('id', $solicitud)->lockForUpdate()->first();
            abort_unless($pedido, 404);
            if ($pedido->estado === 'dispensada') {
                abort_unless((int) $pedido->almacen_origen_id === $origen, 409, 'La solicitud ya fue dispensada desde otro almacén.');

                return;
            }
            abort_unless($pedido->estado === 'pendiente', 409);
            $destino = (int) $pedido->almacen_destino_id;
            abort_if($origen === $destino, 422, 'El origen y destino deben ser diferentes.');
            $almacenes = $db->table('almacenes_inventario')->whereIn('id', [$origen, $destino])->orderBy('id')->lockForUpdate()->get();
            abort_unless($almacenes->count() === 2 && $almacenes->every(fn ($a): bool => (bool) $a->activo), 422, 'Los almacenes deben estar activos.');
            $movimientos = [];
            foreach (collect(json_decode($pedido->detalles, true, 512, JSON_THROW_ON_ERROR))->sortBy('item_id') as $detalle) {
                abort_unless($this->catalogo()->where('i.id', $detalle['item_id'])->where('i.estado', 'ACTIVO')->where('p.estado', 'ACTIVO')->exists(), 422, 'Un insumo ya no está activo.');
                $restante = (int) round($detalle['cantidad'] * 1000);
                $lotes = $db->table('existencias_lote as e')->join('lotes_items as l', 'l.id', '=', 'e.lote_id')
                    ->where('e.almacen_id', $origen)->where('l.item_id', $detalle['item_id'])->where('e.cantidad', '>', 0)
                    ->where(fn ($q) => $q->whereNull('l.vencimiento')->orWhere('l.vencimiento', '>=', now()->toDateString()))
                    ->orderByRaw('CASE WHEN l.vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('l.vencimiento')->orderBy('e.id')->lockForUpdate()->get(['e.id', 'e.lote_id', 'e.cantidad', 'e.costo_unitario']);
                foreach ($lotes as $lote) {
                    $cantidad = min($restante, (int) round($lote->cantidad * 1000));
                    if ($cantidad <= 0) {
                        continue;
                    }
                    $db->table('existencias_lote')->where('id', $lote->id)->decrement('cantidad', $cantidad / 1000);
                    $existencia = $db->table('existencias_lote')->where('lote_id', $lote->lote_id)->where('almacen_id', $destino)->lockForUpdate()->first();
                    if ($existencia) {
                        $db->table('existencias_lote')->where('id', $existencia->id)->increment('cantidad', $cantidad / 1000, ['updated_at' => now()]);
                    } else {
                        $db->table('existencias_lote')->insert(['lote_id' => $lote->lote_id, 'almacen_id' => $destino, 'cantidad' => $cantidad / 1000, 'costo_unitario' => $lote->costo_unitario, 'fecha_ingreso' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $movimientos[] = ['lote_id' => $lote->lote_id, 'item_id' => $detalle['item_id'], 'cantidad' => $cantidad / 1000];
                    $restante -= $cantidad;
                }
                if ($restante > 0) {
                    throw ValidationException::withMessages(['stock' => 'Stock no vencido insuficiente para '.$detalle['nombre'].'. No se dispensó ningún insumo.']);
                }
            }
            $db->table('clinico_solicitudes_insumos')->where('id', $solicitud)->update(['estado' => 'dispensada', 'almacen_origen_id' => $origen, 'dispensado_por' => $request->user()->id, 'dispensado_en' => now(), 'movimientos' => json_encode($movimientos, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }, 3);

        return response()->json(['message' => 'Dispensación registrada. El stock ya está disponible en el almacén de destino.']);
    }
}
