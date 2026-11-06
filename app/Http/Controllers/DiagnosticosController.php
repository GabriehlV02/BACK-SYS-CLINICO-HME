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

        $normalizada = strtr(mb_strtoupper($busqueda, 'UTF-8'), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
        $nombreNormalizado = 'UPPER(nombre)';
        foreach (['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U'] as $acentuada => $simple) {
            $nombreNormalizado = "REPLACE({$nombreNormalizado}, '{$acentuada}', '{$simple}')";
        }

        $resultados = DB::table('diagnosticos')
            ->select('codigo', 'nombre', 'idioma_nombre')
            ->where(function ($consulta) use ($normalizada, $nombreNormalizado): void {
                $consulta->whereRaw('UPPER(codigo) LIKE ?', [$normalizada.'%'])
                    ->orWhereRaw("{$nombreNormalizado} LIKE ?", ['%'.$normalizada.'%']);
            })
            ->orderBy('nombre')->limit(20)->get();

        return response()->json(['data' => $resultados]);
    }
}
