<?php

namespace App\Flows\Autenticacion\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SesionCompartidaController extends Controller
{
    public function show(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        return response()->json(['usuario' => $acceso->datos($request->user())])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, AccesoCompartido $acceso): Response
    {
        $acceso->revocar($request->bearerToken());

        return response()->noContent();
    }
}
