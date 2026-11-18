<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MiActividadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $eventos = DB::table('auditorias_usuarios')
            ->where('usuario_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'categoria', 'accion', 'ip', 'created_at'])
            ->map(fn (object $evento): array => [
                'id' => (string) $evento->id,
                'tipo' => $evento->categoria,
                'creadoEn' => $evento->created_at,
                'detalle' => $evento->accion,
                'ip' => $evento->ip,
            ]);

        return response()->json(['eventos' => $eventos]);
    }
}
