<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditoriaUsuariosController
{
    public function index(Request $request, string $usuario, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'USUARIOS_VER');
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! empty($filtros['desde']) && ! empty($filtros['hasta']) && strtotime($filtros['desde']) > strtotime($filtros['hasta'])) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }

        $consulta = DB::table('auditorias_usuarios')->where('usuario_id', $usuario);
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('created_at', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('created_at', '<=', $filtros['hasta']);
        }

        $eventos = $consulta->orderByDesc('created_at')->orderByDesc('id')->paginate($filtros['per_page'] ?? 50);

        return response()->json([
            'eventos' => $eventos->items(),
            'pagina' => $eventos->currentPage(),
            'ultimaPagina' => $eventos->lastPage(),
            'total' => $eventos->total(),
        ]);
    }
}
