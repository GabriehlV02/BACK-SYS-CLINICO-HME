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
        if (in_array(config('mail.default'), ['log', 'array'], true)
            || (config('mail.default') === 'smtp' && (! config('mail.mailers.smtp.username') || ! config('mail.mailers.smtp.password')))) {
            return response()->json(['message' => 'La recuperación por correo no está configurada. Contacta al administrador del sistema.'], 503);
        }

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
                Cache::forget($this->cacheKey($identificador));
                report($exception);

                return response()->json(['message' => 'No se pudo enviar el correo de recuperación. Intenta nuevamente o contacta al administrador.'], 503);
            }
        }

        return response()->json(['message' => 'Si los datos coinciden con una cuenta activa, recibirás un código para restablecer tu contraseña.']);
    }

    private function cacheKey(string $identificador): string
    {
        return 'recuperacion-contrasena:'.hash('sha256', $identificador);
    }
}
