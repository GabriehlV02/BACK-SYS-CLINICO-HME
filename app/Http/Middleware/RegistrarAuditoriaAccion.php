<?php

namespace App\Http\Middleware;

use App\Flows\Usuarios\Services\RegistrarAccionAuditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistrarAuditoriaAccion
{
    public function __construct(private RegistrarAccionAuditoria $auditoria) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $response->getStatusCode() >= 400) {
            return $response;
        }

        $usuario = $request->user();
        if (! $usuario) {
            return $response;
        }

        $datos = $this->auditoria->describir($request);
        $this->auditoria->registrar(
            (string) $usuario->id,
            $datos['categoria'],
            $datos['accion'],
            $request->method(),
            $datos['ruta'],
            $request->ip(),
            $datos['paciente_id'],
            $datos['servicio'],
        );

        return $response;
    }
}
