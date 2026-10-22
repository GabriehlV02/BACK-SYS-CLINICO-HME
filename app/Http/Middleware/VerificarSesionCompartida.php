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
        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
