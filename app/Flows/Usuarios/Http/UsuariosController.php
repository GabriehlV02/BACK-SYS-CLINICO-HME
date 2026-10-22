<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Usuarios\Models\UsuarioSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuariosController
{
    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'USUARIOS_VER');
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', 'in:ACTIVO,INACTIVO'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $usuarios = UsuarioSistema::where('tipo_usuario', 'EMPRESA');
        if (! empty($datos['estado'])) {
            $usuarios->where('estado', $datos['estado']);
        }
        if (! empty($datos['buscar'])) {
            $buscar = '%'.$datos['buscar'].'%';
            $usuarios->where(fn ($q) => $q->whereLike('nombres', $buscar)->orWhereLike('apellidos', $buscar)->orWhereLike('usuario', $buscar));
        }
        $resultado = $usuarios->orderBy('nombres')->orderBy('id')->paginate($datos['por_pagina'] ?? 20);
        $roles = $acceso->conexion()->table('rol_usuario as ru')->join('roles as r', 'r.id', '=', 'ru.rol_id')->whereIn('ru.usuario_id', $resultado->getCollection()->pluck('id'))->get(['ru.usuario_id', 'r.nombre'])->groupBy('usuario_id');
        $resultado->getCollection()->transform(function (UsuarioSistema $usuario) use ($roles): UsuarioSistema {
            $usuario->rol = $roles->get($usuario->id)?->pluck('nombre')->implode(', ') ?? 'Sin rol';

            return $usuario;
        });

        return response()->json($resultado);
    }

    public function store(RegistrarUsuarioRequest $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validated();
        $db = $acceso->conexion();
        $permisosRol = $db->table('permiso_rol as pr')->join('permisos as p', 'p.id', '=', 'pr.permiso_id')->where('pr.rol_id', $datos['rol'])->pluck('p.codigo')->all();
        if (array_diff($permisosRol, $acceso->permisos($request->user()->id)) !== []) {
            throw ValidationException::withMessages(['rol' => 'No puedes asignar un rol con permisos que no tienes.']);
        }
        $usuario = $db->transaction(function () use ($datos, $db): UsuarioSistema {
            $nombreRol = $db->table('roles')->where('id', $datos['rol'])->value('nombre');
            $usuario = UsuarioSistema::create(['tipo_usuario' => 'EMPRESA', 'nombres' => $datos['nombres'],
                'apellidos' => trim($datos['apellido_paterno'].' '.($datos['apellido_materno'] ?? '')), 'ci' => $datos['ci'], 'correo' => $datos['correo'],
                'cajas' => [$datos['caja']], 'usuario' => $datos['usuario'], 'password' => Hash::make($datos['password']), 'rol' => $nombreRol, 'estado' => $datos['estado'] ?? 'ACTIVO']);
            $db->table('rol_usuario')->insert(['usuario_id' => $usuario->id, 'rol_id' => $datos['rol']]);

            return $usuario;
        });

        return response()->json(['data' => $usuario], 201);
    }
}
