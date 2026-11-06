<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosticosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['required', 'string', 'min:2', 'max:100']]);
        $busqueda = trim($datos['buscar']);
        if (mb_strlen($busqueda) < 2) {
            return response()->json(['data' => []]);
        }

        $resultados = DB::table('diagnosticos')
            ->select('codigo', 'nombre', 'idioma_nombre')
            ->where(function ($consulta) use ($busqueda): void {
                $consulta->where('codigo', 'like', $busqueda.'%')
                    ->orWhere('nombre', 'like', '%'.$busqueda.'%');
            })
            ->orderBy('nombre')->limit(20)->get();

        return response()->json(['data' => $resultados]);
    }
}
