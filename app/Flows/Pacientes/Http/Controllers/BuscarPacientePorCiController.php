<?php

namespace App\Flows\Pacientes\Http\Controllers;

use App\Flows\Pacientes\Models\Paciente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuscarPacientePorCiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['ci' => ['required', 'string', 'min:3', 'max:25']]);
        $paciente = Paciente::query()
            ->where('ci', $datos['ci'])
            ->with(['responsables' => fn ($query) => $query->where('activo', true)->latest()])
            ->first();

        return response()->json(['data' => $paciente]);
    }
}
