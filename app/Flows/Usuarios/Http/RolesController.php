<?php

namespace App\Flows\Usuarios\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolesController
{
    public function index(): JsonResponse
    {
        $roles = DB::table('roles')->where('activo', true)->orderBy('nombre')->get()->map(function (object $rol) {
            $rol->permisos = DB::table('permisos')->join('permiso_rol', 'permiso_rol.permiso_id', '=', 'permisos.id')->where('permiso_rol.rol_id', $rol->id)->pluck('codigo')->values();
            return $rol;
        });
        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($datos, $id): void {
            DB::table('roles')->insert(['id' => $id, 'codigo' => Str::upper(Str::snake($datos['nombre'])), 'nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->sincronizarPermisos($id, $datos['permisos'] ?? []);
        });
        return response()->json(['data' => DB::table('roles')->where('id', $id)->first()], 201);
    }

    public function update(Request $request, string $rol): JsonResponse
    {
        abort_unless(DB::table('roles')->where('id', $rol)->exists(), 404, 'El rol no existe.');
        $datos = $this->validar($request);
        DB::transaction(function () use ($datos, $rol): void {
            DB::table('roles')->where('id', $rol)->update(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => $datos['activo'] ?? true, 'updated_at' => now()]);
            DB::table('permiso_rol')->where('rol_id', $rol)->delete();
            $this->sincronizarPermisos($rol, $datos['permisos'] ?? []);
        });
        return response()->json(['data' => DB::table('roles')->where('id', $rol)->first()]);
    }

    private function validar(Request $request): array
    {
        return $request->validate(['nombre' => ['required', 'string', 'max:100'], 'descripcion' => ['nullable', 'string'], 'activo' => ['sometimes', 'boolean'], 'permisos' => ['array'], 'permisos.*' => ['string', 'exists:permisos,codigo']]);
    }

    private function sincronizarPermisos(string $rolId, array $codigos): void
    {
        foreach (DB::table('permisos')->whereIn('codigo', $codigos)->pluck('id') as $permisoId) {
            DB::table('permiso_rol')->insert(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
        }
    }
}
