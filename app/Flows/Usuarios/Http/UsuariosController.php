<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Usuarios\Models\UsuarioSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UsuariosController
{
    public function index(Request $request): JsonResponse
    {
        $usuarios = UsuarioSistema::query();

        if ($request->filled('tipo_usuario')) {
            $usuarios->where('tipo_usuario', $request->string('tipo_usuario'));
        }

        if ($request->filled('estado')) {
            $usuarios->where('estado', $request->string('estado'));
        }

        if ($request->filled('buscar')) {
            $busqueda = $request->string('buscar')->toString();
            $usuarios->where(function ($query) use ($busqueda): void {
                $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellido_paterno', 'like', "%{$busqueda}%")
                    ->orWhere('apellido_materno', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('usuario', 'like', "%{$busqueda}%");
            });
        }

        $resultado = $usuarios->latest()->paginate($request->integer('por_pagina', 20));
        $resultado->getCollection()->transform(function (UsuarioSistema $usuario) {
            $usuario->rol = DB::table('roles')->join('rol_usuario', 'rol_usuario.rol_id', '=', 'roles.id')->where('rol_usuario.usuario_id', $usuario->id)->value('roles.nombre');
            return $usuario;
        });
        return response()->json($resultado);
    }

    public function store(RegistrarUsuarioRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $rolId = $datos['rol'];
        $datos['rol'] = DB::table('roles')->where('id', $rolId)->value('nombre');
        $datos['password'] = Hash::make($datos['password']);
        $datos['estado'] = $datos['estado'] ?? 'ACTIVO';

        $usuario = UsuarioSistema::create($datos);
        DB::table('rol_usuario')->insert(['usuario_id' => $usuario->id, 'rol_id' => $rolId]);
        $usuario->rol = DB::table('roles')->where('id', $rolId)->value('nombre');
        return response()->json(['data' => $usuario], 201);
    }
}
