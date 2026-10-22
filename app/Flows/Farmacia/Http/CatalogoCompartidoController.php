<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Farmacia\CatalogoCompartido;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CatalogoCompartidoController extends Controller
{
    public function __invoke(CatalogoCompartido $catalogo): JsonResponse
    {
        return response()->json(['data' => $catalogo->obtener()])->header('Cache-Control', 'no-store');
    }
}
