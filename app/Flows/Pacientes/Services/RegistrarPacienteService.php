<?php

namespace App\Flows\Pacientes\Services;

use App\Flows\Pacientes\Models\Paciente;
use App\Flows\Pacientes\Models\RegistroAdmisionPaciente;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RegistrarPacienteService
{
    public function ejecutar(array $datos): Paciente
    {
        return DB::transaction(function () use ($datos): Paciente {
            $datos['ci_con_qr'] = $datos['ci_con_qr'] ?? false;
            $datos['afroamericano'] = $datos['afroamericano'] ?? false;
            $paciente = Paciente::query()->create(Arr::except($datos, ['como_se_entero', 'observaciones', 'habilitado']));
            RegistroAdmisionPaciente::query()->create([
                'paciente_id' => $paciente->id,
                'como_se_entero' => $datos['como_se_entero'],
                'observaciones' => $datos['observaciones'] ?? null,
                'habilitado' => $datos['habilitado'] ?? true,
            ]);
            return $paciente->load('registrosAdmision');
        });
    }
}
