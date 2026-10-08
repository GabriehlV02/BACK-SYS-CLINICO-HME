<?php

namespace App\Flows\Pacientes\Http\Controllers;

use App\Flows\Pacientes\Requests\RegistrarPacienteRequest;
use App\Flows\Pacientes\Services\RegistrarPacienteService;
use Illuminate\Http\JsonResponse;

class RegistrarPacienteController
{
    public function __invoke(RegistrarPacienteRequest $request, RegistrarPacienteService $service): JsonResponse
    {
        $paciente = $service->ejecutar($request->validated());

        return response()->json(['data' => $paciente], 201);
    }
}
