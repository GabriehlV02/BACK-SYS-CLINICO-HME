<?php

namespace App\Flows\Autenticacion\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Mail\CodigoRecuperacionContrasena;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SolicitarRecuperacionContrasenaController
{
    public function __invoke(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate(['identificador' => ['required', 'string', 'max:150']]);
        $identificador = Str::lower(trim($datos['identificador']));
        $usuario = $acceso->conexion()->table('usuarios_sistema')
            ->where(function ($consulta) use ($identificador): void {
                $consulta->whereRaw('LOWER(usuario) = ?', [$identificador])
                    ->orWhereRaw('LOWER(correo) = ?', [$identificador]);
            })->first();

        if ($usuario && $usuario->correo && $acceso->permitido($usuario)) {
            $codigo = (string) random_int(100000, 999999);
            Cache::put($this->cacheKey($identificador), [
                'usuario_id' => $usuario->id,
                'codigo_hash' => hash('sha256', $codigo),
            ], now()->addMinutes(15));

            try {
                Mail::to($usuario->correo)->send(new CodigoRecuperacionContrasena($codigo));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['message' => 'Si los datos coinciden con una cuenta activa, recibirÃ¡s un cÃ³digo para restablecer tu contraseÃ±a.']);
    }

    private function cacheKey(string $identificador): string
    {
        return 'recuperacion-contrasena:'.hash('sha256', $identificador);
    }
}
