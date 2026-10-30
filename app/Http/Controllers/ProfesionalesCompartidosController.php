<?php

namespace App\Http\Controllers;

use App\Flows\Atenciones\ProfesionalesCompartidos;
use Illuminate\Http\JsonResponse;

class ProfesionalesCompartidosController extends Controller
{
    public function __invoke(ProfesionalesCompartidos $catalogo): JsonResponse
    {
        return response()->json(['data' => $catalogo->obtener()]);
    }
}
