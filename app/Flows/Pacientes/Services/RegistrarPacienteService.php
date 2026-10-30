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
            $responsable = [
                'nombre_completo' => $datos['responsable_nombre_completo'] ?? null,
                'ci' => isset($datos['responsable_ci']) ? trim($datos['responsable_ci']) : null,
                'telefono' => $datos['responsable_telefono'] ?? null,
                'parentesco' => $datos['responsable_parentesco'] ?? null,
            ];
            $paciente = Paciente::query()->create(Arr::except($datos, ['como_se_entero', 'observaciones', 'habilitado', 'responsable_nombre_completo', 'responsable_ci', 'responsable_telefono', 'responsable_parentesco']));
            $documento = strtoupper(preg_replace('/[\s-]+/', '', trim($paciente->ci)) ?? '');
            DB::table('atenciones')->whereNull('paciente_id')->whereIn('id',
                DB::table('emergencias')->select('atencion_id')->where('paciente_activo_ci', $documento)
            )->update(['paciente_id' => $paciente->id, 'updated_at' => now()]);
            RegistroAdmisionPaciente::query()->create([
                'paciente_id' => $paciente->id,
                'como_se_entero' => $datos['como_se_entero'],
                'observaciones' => $datos['observaciones'] ?? null,
                'habilitado' => $datos['habilitado'] ?? true,
            ]);
            if ($responsable['nombre_completo']) {
                $pacienteVinculado = ! empty($responsable['ci'])
                    ? Paciente::query()->where('ci', $responsable['ci'])->where('id', '!=', $paciente->id)->first()
                    : null;
                $paciente->responsables()->create([
                    ...$responsable,
                    'paciente_vinculado_id' => $pacienteVinculado?->id,
                    'activo' => true,
                ]);
            }

            return $paciente->load(['registrosAdmision', 'responsables']);
        });
    }
}
