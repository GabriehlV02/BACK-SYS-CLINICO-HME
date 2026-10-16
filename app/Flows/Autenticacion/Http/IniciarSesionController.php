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

        if (config('app.temporary_admin_login') && $datos['usuario'] === 'admin' && $datos['password'] === 'admin') {
            return response()->json([
                'token' => Str::random(64),
                'expiresAt' => now()->addHours(8)->getTimestampMs(),
                'usuario' => [
                    'id' => 'admin-temporal',
                    'usuario' => 'admin',
                    'nombre' => 'Administrador',
                    'apellidos' => 'Temporal',
                    'correo' => 'admin@temporal.local',
                    'rol' => 'Administrador',
                    'permisos' => [
                        'recepcion.ver', 'recepcion.caja.ver', 'recepcion.agenda.ver', 'recepcion.citas.gestionar',
                        'pacientes.ver', 'pacientes.registrar', 'pacientes.editar', 'historias.clinicas.ver',
                        'consultorio.ver', 'consultas.atender', 'recetas.emitir',
                        'internacion.ver', 'internacion.gestionar', 'signos.vitales.registrar',
                        'imagenologia.ver', 'imagenologia.informar', 'laboratorio.ver', 'laboratorio.informar',
                        'usuarios.ver', 'usuarios.crear', 'roles.gestionar', 'auditoria.ver',
                    ],
                    'tipoInicio' => 'admin_sistema',
                ],
            ]);
        }

        $conexion = DB::connection();
        $usuario = $conexion->table('usuarios_sistema')->where('usuario', $datos['usuario'])->where('estado', 'ACTIVO')->first();

        if (! $usuario) {
            $conexion = DB::connection('usuarios');
            $usuario = $conexion->table('usuarios_sistema')->where('usuario', $datos['usuario'])->where('estado', 'ACTIVO')->first();
        }

        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['message' => 'Usuario o contraseña incorrectos.'], 422);
        }

        $roles = $conexion->table('roles as r')->join('rol_usuario as ru', 'ru.rol_id', '=', 'r.id')->where('ru.usuario_id', $usuario->id)->where('r.activo', true)->pluck('r.nombre');
        $permisos = $conexion->table('permisos as p')->join('permiso_rol as pr', 'pr.permiso_id', '=', 'p.id')->join('rol_usuario as ru', 'ru.rol_id', '=', 'pr.rol_id')->where('ru.usuario_id', $usuario->id)->where('p.activo', true)->distinct()->pluck('p.codigo')->map(fn (string $codigo) => strtolower(str_replace('_', '.', $codigo)))->values();

        return response()->json([
            'token' => Str::random(64),
            'expiresAt' => now()->addHours(8)->getTimestampMs(),
            'usuario' => ['id' => $usuario->id, 'usuario' => $usuario->usuario, 'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"), 'apellidos' => $usuario->apellidos, 'correo' => $usuario->correo, 'ci' => $usuario->ci, 'telefono' => $usuario->telefono ?? null, 'rol' => $roles->first() ?? 'Sin rol', 'permisos' => $permisos, 'tipoInicio' => 'admin_sistema'],
        ]);
    }
}
