<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RolesController
{
    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $permisos = $acceso->permisos($request->user()->id);
        abort_unless(array_intersect($permisos, ['USUARIOS_VER', 'USUARIOS_CREAR', 'ROLES_GESTIONAR']) !== [], 403);
        $db = $acceso->conexion();
        $roles = $db->table('roles')->where('activo', true)->orderBy('nombre')->get();
        $asignados = $db->table('permiso_rol as pr')->join('permisos as p', 'p.id', '=', 'pr.permiso_id')->get(['pr.rol_id', 'p.codigo'])->groupBy('rol_id');

        return response()->json(['data' => $roles->map(function (object $rol) use ($asignados): object {
            $rol->permisos = $asignados->get($rol->id)?->pluck('codigo')->all() ?? [];

            return $rol;
        })]);
    }

    public function store(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        return $this->guardar($request, $acceso, null);
    }

    public function update(Request $request, string $rol, AccesoCompartido $acceso): JsonResponse
    {
        return $this->guardar($request, $acceso, $rol);
    }

    private function guardar(Request $request, AccesoCompartido $acceso, ?string $rol): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'ROLES_GESTIONAR');
        $db = $acceso->conexion();
        $actual = $rol ? $db->table('roles')->where('id', $rol)->first() : null;
        abort_if($rol && ! $actual, 404);
        if ($actual?->codigo === 'ADMINISTRADOR') {
            throw ValidationException::withMessages(['nombre' => 'El administrador compartido está protegido. Administra este rol desde el sistema contable.']);
        }
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:100', Rule::unique('usuarios.roles', 'nombre')->ignore($rol)],
            'descripcion' => ['nullable', 'string', 'max:1000'], 'activo' => ['sometimes', 'boolean'], 'permisos' => ['present', 'array'],
            'permisos.*' => ['string', 'distinct', Rule::exists('usuarios.permisos', 'codigo')->where('activo', true)]]);
        if (array_diff($datos['permisos'], $acceso->permisos($request->user()->id)) !== []) {
            throw ValidationException::withMessages(['permisos' => 'No puedes otorgar permisos que no tienes asignados.']);
        }
        $id = $rol ?? (string) Str::uuid();
        $db->transaction(function () use ($datos, $id, $actual, $db): void {
            $ahora = now();
            if ($actual) {
                $db->table('roles')->where('id', $id)->update(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => $datos['activo'] ?? true, 'updated_at' => $ahora]);
            } else {
                $db->table('roles')->insert(['id' => $id, 'codigo' => 'CLINICO_'.Str::upper(Str::random(20)), 'nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            $db->table('permiso_rol')->where('rol_id', $id)->delete();
            $permisos = $db->table('permisos')->whereIn('codigo', $datos['permisos'])->get(['id', 'sistema_id']);
            foreach ($permisos as $permiso) {
                $db->table('permiso_rol')->insert(['rol_id' => $id, 'permiso_id' => $permiso->id]);
            }
            $sistemas = $permisos->pluck('sistema_id')->filter()->push($db->table('sistemas')->where('codigo', 'CLINICO')->value('id'))->filter()->unique();
            foreach ($sistemas as $sistemaId) {
                $db->table('rol_sistema')->updateOrInsert(['rol_id' => $id, 'sistema_id' => $sistemaId]);
            }
        });

        return response()->json(['data' => $db->table('roles')->where('id', $id)->first()], $rol ? 200 : 201);
    }
}
