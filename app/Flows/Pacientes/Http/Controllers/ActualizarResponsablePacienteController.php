<?php

namespace App\Flows\Pacientes\Http\Controllers;

use App\Flows\Pacientes\Models\Paciente;
use App\Flows\Pacientes\Models\PacienteResponsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActualizarResponsablePacienteController
{
    public function __invoke(Request $request, Paciente $paciente): JsonResponse
    {
        $datos = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:180'],
            'ci' => ['nullable', 'string', 'min:3', 'max:25'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'parentesco' => ['nullable', 'string', 'max:80'],
        ]);

        $datos['ci'] = isset($datos['ci']) ? trim($datos['ci']) : null;
        $pacienteVinculado = !empty($datos['ci'])
            ? Paciente::query()->where('ci', $datos['ci'])->where('id', '!=', $paciente->id)->first()
            : null;

        $responsable = DB::transaction(function () use ($paciente, $datos, $pacienteVinculado): PacienteResponsable {
            $paciente->responsables()->where('activo', true)->update(['activo' => false]);
            return $paciente->responsables()->create([
                ...$datos,
                'paciente_vinculado_id' => $pacienteVinculado?->id,
                'activo' => true,
            ]);
        });

        return response()->json(['data' => $responsable], 201);
    }
}
