<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AlmacenesCajasController
{
    public function __construct(private AccesoCompartido $acceso) {}

    public function index(): JsonResponse
    {
        $almacenes = $this->almacenes();
        $asignaciones = DB::table('caja_almacen')->get()->groupBy('caja_id');
        $permitidos = $this->acceso->almacenesPermitidos();
        $cajas = DB::table('cajas')->when($permitidos !== null, fn ($q) => $q->whereIn('id', DB::table('caja_almacen')->whereIn('almacen_id', $permitidos)->select('caja_id')))->orderBy('nombre')->get()->map(function (object $caja) use ($almacenes, $asignaciones): object {
            $caja->tipos_item_permitidos = json_decode($caja->tipos_item_permitidos ?? '[]', true);
            $ids = ($asignaciones[$caja->id] ?? collect())->pluck('almacen_id');
            $caja->almacenes = $almacenes->whereIn('id', $ids)->values();

            return $caja;
        });

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

    public function storeCaja(Request $request): JsonResponse
    {
        $this->acceso->exigir($request->user()->id, 'USUARIOS_CREAR');
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'], 'sucursal' => ['required', 'string', 'max:120'],
            'almacen_principal_id' => ['required', 'integer', Rule::exists('usuarios.almacenes_inventario', 'id')->where('activo', true)],
            'almacenes' => ['nullable', 'array'], 'almacenes.*' => ['integer', Rule::exists('usuarios.almacenes_inventario', 'id')->where('activo', true)],
            'tipos_item_permitidos' => ['nullable', 'array'], 'tipos_item_permitidos.*' => [Rule::in(['Producto', 'Insumo', 'Servicio', 'Paquete'])],
            'limite_items' => ['nullable', 'integer', 'min:1'],
        ]);
        $id = (string) Str::uuid();
        $asignados = collect($data['almacenes'] ?? [])->push($data['almacen_principal_id'])->unique()->values();
        $activos = $this->almacenes()->where('estado', 'ACTIVO')->pluck('id');
        abort_unless($asignados->diff($activos)->isEmpty(), 422, 'Los almacenes deben pertenecer a sucursales activas.');
        DB::transaction(function () use ($data, $id, $asignados): void {
            DB::table('cajas')->insert([
                'id' => $id, 'nombre' => $data['nombre'], 'sucursal' => $data['sucursal'],
                'almacen_principal_id' => $data['almacen_principal_id'], 'tipos_item_permitidos' => json_encode($data['tipos_item_permitidos'] ?? []),
                'limite_items' => $data['limite_items'] ?? null, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('caja_almacen')->insert($asignados->map(fn ($almacenId): array => ['caja_id' => $id, 'almacen_id' => $almacenId])->all());
        });

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function cambiarEstado(Request $request, string $tipo, string $id): JsonResponse
    {
        abort_unless($this->acceso->almacenesPermitidos() === null, 403);
        abort_unless(in_array($tipo, ['almacenes', 'cajas'], true), 404);
        $this->acceso->exigir($request->user()->id, $tipo === 'almacenes' ? 'INVENTARIO_GESTIONAR' : 'USUARIOS_CREAR');
        if ($tipo === 'almacenes') {
            abort_unless(ctype_digit($id), 404);
            $estado = $this->acceso->conexion()->transaction(function () use ($id): string {
                $tabla = $this->acceso->conexion()->table('almacenes_inventario');
                $actual = (clone $tabla)->where('id', $id)->lockForUpdate()->first();
                abort_unless($actual, 404);
                $tabla->where('id', $id)->update(['activo' => ! $actual->activo, 'updated_at' => now()]);

                return $actual->activo ? 'INACTIVO' : 'ACTIVO';
            });
        } else {
            abort_unless(Str::isUuid($id), 404);
            $estado = DB::transaction(function () use ($id): string {
                $actual = DB::table('cajas')->where('id', $id)->lockForUpdate()->first();
                abort_unless($actual, 404);
                $estado = $actual->estado === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
                DB::table('cajas')->where('id', $id)->update(['estado' => $estado, 'updated_at' => now()]);

                return $estado;
            });
        }

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
