<?php

namespace App\Flows\Pacientes\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClasificacionesEstudioController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('estudio_clasificacion')
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }
}
