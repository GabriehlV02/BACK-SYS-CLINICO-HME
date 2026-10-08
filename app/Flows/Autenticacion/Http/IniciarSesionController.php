<?php

namespace App\Flows\Autenticacion\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IniciarSesionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['usuario' => ['required', 'string'], 'password' => ['required', 'string']]);
        $usuario = DB::connection('usuarios')->table('usuarios_sistema')->where('usuario', $datos['usuario'])->where('estado', 'ACTIVO')->first();

        if (!$usuario || !Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['message' => 'Usuario o contraseña incorrectos.'], 422);
        }

        $roles = DB::connection('usuarios')->table('roles as r')->join('rol_usuario as ru', 'ru.rol_id', '=', 'r.id')->where('ru.usuario_id', $usuario->id)->where('r.activo', true)->pluck('r.nombre');
        $permisos = DB::connection('usuarios')->table('permisos as p')->join('permiso_rol as pr', 'pr.permiso_id', '=', 'p.id')->join('rol_usuario as ru', 'ru.rol_id', '=', 'pr.rol_id')->where('ru.usuario_id', $usuario->id)->where('p.activo', true)->distinct()->pluck('p.codigo')->map(fn (string $codigo) => strtolower(str_replace('_', '.', $codigo)))->values();

        return response()->json([
            'token' => Str::random(64),
            'expiresAt' => now()->addHours(8)->getTimestampMs(),
            'usuario' => ['id' => $usuario->id, 'usuario' => $usuario->usuario, 'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"), 'apellidos' => $usuario->apellidos, 'correo' => $usuario->correo, 'ci' => $usuario->ci, 'telefono' => $usuario->telefono, 'rol' => $roles->first() ?? 'Sin rol', 'permisos' => $permisos, 'tipoInicio' => 'admin_sistema'],
        ]);
    }
}
