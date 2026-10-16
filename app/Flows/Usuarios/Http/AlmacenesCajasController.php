<?php

namespace App\Flows\Usuarios\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AlmacenesCajasController
{
    public function index(): JsonResponse
    {
        $almacenes = DB::table('almacenes')->orderBy('nombre')->get();
        $cajas = DB::table('cajas')->orderBy('nombre')->get()->map(function (object $caja) {
            $caja->tipos_item_permitidos = json_decode($caja->tipos_item_permitidos ?? '[]', true);
            $caja->almacenes = DB::table('almacenes')->join('caja_almacen', 'caja_almacen.almacen_id', '=', 'almacenes.id')->where('caja_almacen.caja_id', $caja->id)->select('almacenes.id', 'almacenes.nombre', 'almacenes.codigo')->get();
            return $caja;
        });
        return response()->json(compact('almacenes', 'cajas'));
    }
    public function storeAlmacen(Request $request): JsonResponse
    {
        $data = $request->validate(['nombre' => ['required', 'string', 'max:120'], 'codigo' => ['required', 'string', 'max:30', Rule::unique('almacenes', 'codigo')], 'sucursal' => ['required', 'string', 'max:120'], 'descripcion' => ['nullable', 'string']]);
        $data['id'] = (string) Str::uuid(); $data['estado'] = 'ACTIVO'; $data['created_at'] = now(); $data['updated_at'] = now();
        DB::table('almacenes')->insert($data); return response()->json(['data' => $data], 201);
    }
    public function storeCaja(Request $request): JsonResponse
    {
        $data = $request->validate(['nombre' => ['required', 'string', 'max:120'], 'sucursal' => ['required', 'string', 'max:120'], 'almacen_principal_id' => ['required', 'uuid', Rule::exists('almacenes', 'id')], 'almacenes' => ['nullable', 'array'], 'almacenes.*' => ['uuid', Rule::exists('almacenes', 'id')], 'tipos_item_permitidos' => ['nullable', 'array'], 'tipos_item_permitidos.*' => [Rule::in(['Producto', 'Insumo', 'Servicio', 'Paquete'])], 'limite_items' => ['nullable', 'integer', 'min:1']]);
        $id = (string) Str::uuid(); $asignados = collect($data['almacenes'] ?? [])->push($data['almacen_principal_id'])->unique()->values();
        DB::transaction(function () use ($data, $id, $asignados): void { DB::table('cajas')->insert(['id' => $id, 'nombre' => $data['nombre'], 'sucursal' => $data['sucursal'], 'almacen_principal_id' => $data['almacen_principal_id'], 'tipos_item_permitidos' => json_encode($data['tipos_item_permitidos'] ?? []), 'limite_items' => $data['limite_items'] ?? null, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]); DB::table('caja_almacen')->insert($asignados->map(fn ($almacenId) => ['caja_id' => $id, 'almacen_id' => $almacenId])->all()); });
        return response()->json(['data' => ['id' => $id]], 201);
    }
    public function cambiarEstado(string $tipo, string $id): JsonResponse
    {
        abort_unless(in_array($tipo, ['almacenes', 'cajas'], true), 404);
        $tabla = $tipo; $actual = DB::table($tabla)->where('id', $id)->value('estado'); abort_unless($actual, 404);
        $estado = $actual === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO'; DB::table($tabla)->where('id', $id)->update(['estado' => $estado, 'updated_at' => now()]);
        return response()->json(['estado' => $estado]);
    }
}
