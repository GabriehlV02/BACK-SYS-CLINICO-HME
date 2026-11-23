<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AlmacenesCajasController
{
    public function __construct(private AccesoCompartido $acceso) {}

    public function index(): JsonResponse
    {
        $almacenes = $this->almacenes();
        $db = $this->acceso->conexion();
        $asignaciones = $db->table('almacen_caja')->whereIn('almacen_id', $almacenes->pluck('id'))->get()->groupBy('caja_id');
        $permitidos = $this->acceso->almacenesPermitidos();
        $cajas = $db->table('cajas as c')->join('sucursales_inventario as s', 's.id', '=', 'c.sucursal_id')
            ->when($permitidos !== null, fn ($q) => $q->whereIn('c.id', $asignaciones->keys()))
            ->orderBy('c.nombre')->get(['c.id', 'c.nombre', 'c.activo', 's.nombre as sucursal', 's.activo as sucursal_activa'])
            ->map(fn (object $caja): array => [
                'id' => (string) $caja->id, 'nombre' => $caja->nombre, 'sucursal' => $caja->sucursal,
                'estado' => $caja->activo && $caja->sucursal_activa ? 'ACTIVO' : 'INACTIVO',
                'almacenes' => $almacenes->whereIn('id', ($asignaciones[$caja->id] ?? collect())->pluck('almacen_id'))->values(),
            ]);

        return response()->json(['almacenes' => $almacenes->values(), 'cajas' => $cajas]);
    }

    public function storeAlmacen(Request $request): JsonResponse
    {
        abort_unless($this->acceso->almacenesPermitidos() === null, 403);
        $this->acceso->exigir($request->user()->id, 'INVENTARIO_GESTIONAR');
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'codigo' => ['required', 'string', 'max:30', Rule::unique('usuarios.almacenes_inventario', 'codigo')],
            'sucursal' => ['required', 'string', 'max:120'],
        ]);
        $db = $this->acceso->conexion();
        $id = $db->transaction(function () use ($db, $data): int {
            $sucursal = $db->table('sucursales_inventario')->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($data['sucursal']))])->first();
            abort_if($sucursal && ! $sucursal->activo, 422, 'La sucursal está inactiva.');
            $sucursalId = $sucursal?->id ?? $db->table('sucursales_inventario')->insertGetId([
                'nombre' => trim($data['sucursal']), 'codigo' => 'SUC-'.Str::random(20), 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $db->table('almacenes_inventario')->insertGetId([
                'nombre' => $data['nombre'], 'codigo' => $data['codigo'], 'sucursal_id' => $sucursalId,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->almacenes()->firstWhere('id', (string) $id)], 201);
    }

    public function cambiarEstado(Request $request, string $id): JsonResponse
    {
        abort_unless($this->acceso->almacenesPermitidos() === null, 403);
        $this->acceso->exigir($request->user()->id, 'INVENTARIO_GESTIONAR');
            abort_unless(ctype_digit($id), 404);
            $estado = $this->acceso->conexion()->transaction(function () use ($id): string {
                $tabla = $this->acceso->conexion()->table('almacenes_inventario');
                $actual = (clone $tabla)->where('id', $id)->lockForUpdate()->first();
                abort_unless($actual, 404);
                $tabla->where('id', $id)->update(['activo' => ! $actual->activo, 'updated_at' => now()]);

                return $actual->activo ? 'INACTIVO' : 'ACTIVO';
            });


        return response()->json(compact('estado'));
    }

    private function almacenes(): Collection
    {
        $permitidos = $this->acceso->almacenesPermitidos();

        return $this->acceso->conexion()->table('almacenes_inventario as a')
            ->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))
            ->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')->orderBy('a.nombre')->orderBy('a.id')
            ->get(['a.id', 'a.nombre', 'a.codigo', 'a.activo', 's.activo as sucursal_activa', 's.nombre as sucursal'])
            ->map(fn (object $a): array => ['id' => (string) $a->id, 'nombre' => $a->nombre, 'codigo' => $a->codigo, 'sucursal' => $a->sucursal,
                'estado' => $a->activo && $a->sucursal_activa ? 'ACTIVO' : 'INACTIVO']);
    }
}
