<?php

namespace App\Http\Middleware;

use App\Flows\Autenticacion\AccesoCompartido;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarSesionCompartida
{
    public function __construct(private AccesoCompartido $acceso) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $this->acceso->verificar($request->bearerToken());
        $soloInventario = $this->acceso->roles($usuario->id)
            ->where('r.codigo', 'INVENTARIO_ESTERILIZACION')->exists();
        if ($soloInventario && ! in_array($request->path(), ['api/session', 'api/logout', 'api/cambiar-contrasena'], true) && ! $request->is('api/v1/instrumental', 'api/v1/instrumental/*')) {
            abort(403, 'Esta cuenta solo tiene acceso al inventario de esterilizacion.');
        }
        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
