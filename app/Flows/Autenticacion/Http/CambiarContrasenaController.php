<?php

namespace App\Flows\Autenticacion\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CambiarContrasenaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string'],
            'contrasena_actual' => ['required', 'string'],
            'contrasena_nueva' => ['required', 'string', 'min:4', 'same:confirmar_contrasena'],
            'confirmar_contrasena' => ['required', 'string'],
        ]);
        $usuario = DB::connection('usuarios')->table('usuarios_sistema')->where('usuario', $datos['usuario'])->where('estado', 'ACTIVO')->first();
        if (!$usuario || !Hash::check($datos['contrasena_actual'], $usuario->password)) return response()->json(['message' => 'La contraseña actual no es correcta.'], 422);
        DB::connection('usuarios')->table('usuarios_sistema')->where('id', $usuario->id)->update(['password' => Hash::make($datos['contrasena_nueva']), 'updated_at' => now()]);
        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
