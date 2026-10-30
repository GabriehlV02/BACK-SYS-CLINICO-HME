<?php

namespace App\Flows\Pacientes\Http\Controllers;

use App\Flows\Pacientes\Models\Paciente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListarPacientesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'buscar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'paciente_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(Paciente::query()
            ->select('pacientes.*')
            ->selectSub(DB::table('estudios')->selectRaw('count(*)')->whereColumn('estudios.paciente_id', 'pacientes.id')->where('tipo', 'LABORATORIO'), 'laboratorios_count')
            ->selectSub(DB::table('estudios')->selectRaw('count(*)')->whereColumn('estudios.paciente_id', 'pacientes.id')->where('tipo', '!=', 'LABORATORIO'), 'imagenes_count')
            ->when($datos['paciente_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($datos['buscar'] ?? null, fn ($query, $buscar) => $query->where(fn ($query) => $query
                ->whereLike('nombres', '%'.$buscar.'%')->orWhereLike('apellido_paterno', '%'.$buscar.'%')
                ->orWhereLike('apellido_materno', '%'.$buscar.'%')->orWhereLike('ci', '%'.$buscar.'%')))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($datos['por_pagina'] ?? 100));
    }
}
