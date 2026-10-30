<?php

namespace App\Http\Controllers;

use App\Flows\Pacientes\Models\Paciente;
use App\Http\Requests\ActualizarPacienteRequest;
use Illuminate\Http\JsonResponse;

class PacienteFichaController extends Controller
{
    public function show(Paciente $paciente): JsonResponse
    {
        return response()->json(['data' => $paciente->load(['responsables', 'registrosAdmision'])]);
    }

    public function update(ActualizarPacienteRequest $request, Paciente $paciente): JsonResponse
    {
        $paciente->update($request->validated());

        return response()->json(['data' => $paciente->fresh()]);
    }
}
