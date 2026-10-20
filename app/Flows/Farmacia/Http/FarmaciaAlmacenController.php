<?php

namespace App\Flows\Farmacia\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FarmaciaAlmacenController
{
    public function index(Request $request): JsonResponse
    {
        $buscar = $request->string('buscar')->trim()->value();
        $productos = DB::table('farmacia_productos')->when($buscar, fn ($query) => $query->where(fn ($q) => $q->whereLike('nombre', "%{$buscar}%")->orWhereLike('codigo', "%{$buscar}%")))->orderBy('nombre')->get();

        return response()->json(['data' => $productos]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origen' => ['required', Rule::in(['CONTABLE', 'PROPIO'])],
            'contable_producto_id' => ['nullable', 'string', 'max:80', Rule::unique('farmacia_productos', 'contable_producto_id')],
            'codigo' => ['required', 'string', 'max:50', Rule::unique('farmacia_productos', 'codigo')],
            'nombre' => ['required', 'string', 'max:160'], 'descripcion' => ['nullable', 'string'],
            'unidad' => ['required', 'string', 'max:30'], 'precio_venta' => ['required', 'numeric', 'min:0'],
            'stock_inicial' => ['nullable', 'numeric', 'min:0'], 'stock_minimo' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($data['origen'] === 'CONTABLE' && empty($data['contable_producto_id'])) {
            abort(422, 'Selecciona un producto del sistema contable.');
        }
        $id = (string) Str::uuid();
        $stock = (float) ($data['stock_inicial'] ?? 0);
        DB::transaction(function () use ($data, $id, $stock): void {
            DB::table('farmacia_productos')->insert(['id' => $id, 'origen' => $data['origen'], 'contable_producto_id' => $data['contable_producto_id'] ?? null, 'codigo' => $data['codigo'], 'nombre' => $data['nombre'], 'descripcion' => $data['descripcion'] ?? null, 'unidad' => $data['unidad'], 'precio_venta' => $data['precio_venta'], 'stock' => $stock, 'stock_minimo' => $data['stock_minimo'] ?? 0, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
            if ($stock > 0) {
                $this->registrarMovimiento($id, 'INGRESO', $stock, 0, $stock, 'Stock inicial', $data['nombre']);
            }
        });

        return response()->json(['data' => DB::table('farmacia_productos')->find($id)], 201);
    }

    public function movimiento(Request $request, string $producto): JsonResponse
    {
        $data = $request->validate(['tipo' => ['required', Rule::in(['INGRESO', 'VENTA', 'AJUSTE'])], 'cantidad' => ['required', 'numeric', 'not_in:0'], 'nota' => ['nullable', 'string'], 'referencia' => ['nullable', 'string', 'max:100']]);
        $registro = DB::transaction(function () use ($producto, $data) {
            $item = DB::table('farmacia_productos')->where('id', $producto)->lockForUpdate()->first();
            abort_unless($item, 404);
            $cantidad = (float) $data['cantidad'];
            $delta = $data['tipo'] === 'VENTA' ? -abs($cantidad) : $cantidad;
            $posterior = (float) $item->stock + $delta;
            abort_if($posterior < 0, 422, 'Stock insuficiente.');
            DB::table('farmacia_productos')->where('id', $producto)->update(['stock' => $posterior, 'updated_at' => now()]);

            return $this->registrarMovimiento($producto, $data['tipo'], $delta, (float) $item->stock, $posterior, $data['nota'] ?? null, $data['referencia'] ?? null);
        });

        return response()->json(['data' => $registro], 201);
    }

    public function kardex(string $producto): JsonResponse
    {
        return response()->json(['data' => DB::table('farmacia_movimientos')->where('producto_id', $producto)->latest()->get()]);
    }

    public function almacen(): JsonResponse
    {
        return response()->json(['data' => DB::table('farmacia_almacen_items')->orderBy('area_uso')->orderBy('nombre')->get()]);
    }

    public function storeAlmacen(Request $request): JsonResponse
    {
        $data = $request->validate(['item_venta_id' => ['nullable', 'uuid', Rule::exists('farmacia_productos', 'id')], 'nombre' => ['required', 'string', 'max:160'], 'codigo' => ['required', 'string', 'max:50', Rule::unique('farmacia_almacen_items', 'codigo')], 'tipo' => ['required', Rule::in(['VENTA', 'INSUMO'])], 'area_uso' => ['required', Rule::in(['FARMACIA', 'COCINA', 'ATENCION'])], 'unidad' => ['required', 'string', 'max:30'], 'cantidad' => ['nullable', 'numeric', 'min:0'], 'stock_minimo' => ['nullable', 'numeric', 'min:0']]);
        $data += ['id' => (string) Str::uuid(), 'cantidad' => $data['cantidad'] ?? 0, 'stock_minimo' => $data['stock_minimo'] ?? 0, 'activo' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('farmacia_almacen_items')->insert($data);

        return response()->json(['data' => $data], 201);
    }

    private function registrarMovimiento(string $producto, string $tipo, float $cantidad, float $anterior, float $posterior, ?string $nota, ?string $referencia): array
    {
        $data = ['id' => (string) Str::uuid(), 'producto_id' => $producto, 'tipo' => $tipo, 'cantidad' => $cantidad, 'stock_anterior' => $anterior, 'stock_posterior' => $posterior, 'nota' => $nota, 'referencia' => $referencia, 'responsable' => null, 'created_at' => now(), 'updated_at' => now()];
        DB::table('farmacia_movimientos')->insert($data);

        return $data;
    }
}
