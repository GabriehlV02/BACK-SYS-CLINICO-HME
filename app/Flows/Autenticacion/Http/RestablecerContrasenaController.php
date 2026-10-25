<?php

namespace App\Flows\Autenticacion\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RestablecerContrasenaController
{
    public function __invoke(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate([
            'identificador' => ['required', 'string', 'max:150'],
            'codigo' => ['required', 'digits:6'],
            'contrasena_nueva' => ['required', 'string', 'min:8', 'max:200', 'same:confirmar_contrasena'],
            'confirmar_contrasena' => ['required', 'string'],
        ]);
        $identificador = Str::lower(trim($datos['identificador']));
        $clave = $this->cacheKey($identificador);
        $recuperacion = Cache::get($clave);

        if (! is_array($recuperacion) || ! isset($recuperacion['usuario_id'], $recuperacion['codigo_hash']) || ! hash_equals($recuperacion['codigo_hash'], hash('sha256', $datos['codigo']))) {
            return response()->json(['message' => 'El cÃ³digo no es vÃ¡lido o ya expirÃ³. Solicita uno nuevo.'], 422);
        }

        $actualizados = $acceso->conexion()->table('usuarios_sistema')->where('id', $recuperacion['usuario_id'])->where('estado', 'ACTIVO')->update([
            'password' => Hash::make($datos['contrasena_nueva']),
            'updated_at' => now(),
        ]);
        Cache::forget($clave);

        if ($actualizados !== 1) {
            return response()->json(['message' => 'No se pudo restablecer la contraseÃ±a. Solicita un cÃ³digo nuevo.'], 422);
        }

        return response()->json(['message' => 'Tu contraseÃ±a fue actualizada. Ya puedes iniciar sesiÃ³n.']);
    }

    private function cacheKey(string $identificador): string
    {
        return 'recuperacion-contrasena:'.hash('sha256', $identificador);
    }
}
