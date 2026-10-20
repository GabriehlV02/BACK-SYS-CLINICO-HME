<?php

namespace App\Flows\Emergencias\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmergenciasController
{
    private const CUBICULOS = ['1', '2', '3', 'CRITICO'];

    public function index(): JsonResponse
    {
        $cuentas = DB::table('emergencias')
            ->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('atenciones.tipo', 'emergencia')
            ->orderByDesc('atenciones.iniciada_en')
            ->get(['emergencias.id', 'emergencias.identidad_provisional', 'emergencias.datos_ingreso', 'atenciones.iniciada_en', 'atenciones.finalizada_en'])
            ->map(fn (object $emergencia): array => $this->presentar($emergencia))
            ->values();

        return response()->json(['cuentas' => $cuentas]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cubiculo' => ['required', 'string', Rule::in(self::CUBICULOS)],
            'identidad' => ['nullable', 'array'],
            'solicitudId' => ['nullable', 'uuid'],
        ]);

        $emergencia = DB::transaction(function () use ($datos): object {
            $atencionId = (string) Str::uuid();
            $emergenciaId = (string) Str::uuid();
            $codigo = 'EMG-'.strtoupper(Str::substr(Str::uuid()->toString(), 0, 8));

            DB::table('atenciones')->insert([
                'id' => $atencionId,
                'tipo' => 'emergencia',
                'codigo_atencion' => $codigo,
                'estado' => 'en_curso',
                'iniciada_en' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('emergencias')->insert([
                'id' => $emergenciaId,
                'atencion_id' => $atencionId,
                'codigo_emergencia' => $codigo,
                'identidad_provisional' => isset($datos['identidad']) ? json_encode($datos['identidad']) : null,
                'datos_ingreso' => json_encode(['cubiculo' => $datos['cubiculo']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (object) [
                'id' => $emergenciaId,
                'identidad_provisional' => isset($datos['identidad']) ? json_encode($datos['identidad']) : null,
                'datos_ingreso' => json_encode(['cubiculo' => $datos['cubiculo']]),
                'iniciada_en' => now(),
                'finalizada_en' => null,
            ];
        });

        return response()->json($this->presentar($emergencia), 201);
    }

    public function catalogo(): JsonResponse
    {
        return response()->json([]);
    }

    private function presentar(object $emergencia): array
    {
        $ingreso = json_decode($emergencia->datos_ingreso ?? '[]', true) ?: [];
        $identidad = json_decode($emergencia->identidad_provisional ?? 'null', true);

        return [
            'id' => $emergencia->id,
            'cubiculo' => $ingreso['cubiculo'] ?? '',
            'inicio' => $emergencia->iniciada_en,
            'fin' => $emergencia->finalizada_en,
            'revision' => 1,
            'identidad' => $identidad,
            'signos' => [],
            'consumos' => [],
            'evaluaciones' => [],
            'ordenes' => [],
        ];
    }
}
