<?php

namespace App\Flows\Emergencias\Http;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CuentasContablesController
{
    public function __invoke(): JsonResponse
    {
        $cuentas = DB::table('cuentas_atencion_emergencia as c')
            ->join('emergencias as e', 'e.id', '=', 'c.emergencia_id')
            ->join('atenciones as a', 'a.id', '=', 'e.atencion_id')
            ->leftJoin('pacientes as p', 'p.id', '=', 'a.paciente_id')
            ->where('c.estado', 'abierta')
            ->orderByDesc('a.iniciada_en')
            ->get(['c.id', 'c.estado', 'c.moneda', 'e.id as emergencia_id', 'e.identidad_provisional', 'e.datos_ingreso', 'a.codigo_atencion', 'a.iniciada_en', 'a.finalizada_en', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 'p.ci']);
        $cargos = DB::table('cargos_atencion_emergencia')->whereIn('cuenta_id', $cuentas->pluck('id'))
            ->where('vigente', true)->orderBy('created_at')->get(['id', 'cuenta_id', 'datos'])->groupBy('cuenta_id');

        $resultado = $cuentas->map(function ($cuenta) use ($cargos): array {
            $ingreso = json_decode($cuenta->datos_ingreso ?? '{}', true) ?: [];
            $identidad = $ingreso['identidad'] ?? (json_decode($cuenta->identidad_provisional ?? '{}', true) ?: []);
            $paciente = trim(implode(' ', array_filter([$cuenta->nombres, $cuenta->apellido_paterno, $cuenta->apellido_materno])))
                ?: trim(implode(' ', array_filter([$identidad['nombres'] ?? null, $identidad['apellidos'] ?? null]))) ?: 'Paciente sin identificar';
            $detalles = ($cargos[$cuenta->id] ?? collect())->map(function ($cargo): array {
                $datos = json_decode($cargo->datos, true) ?: [];
                $cantidad = (float) ($datos['cantidad'] ?? 1);
                if (! empty($datos['porHora'])) {
                    $ms = max(0, (int) ($datos['tiempoAcumuladoMs'] ?? 0));
                    if (empty($datos['fin']) && ! empty($datos['inicio'])) $ms += max(0, Carbon::parse($datos['inicio'])->diffInMilliseconds(now(), false));
                    if (! isset($datos['tiempoAcumuladoMs']) && ! empty($datos['fin']) && ! empty($datos['inicio'])) $ms = max(0, Carbon::parse($datos['inicio'])->diffInMilliseconds(Carbon::parse($datos['fin']), false));
                    $cantidad = max(0.5, ceil($ms / 1800000) * 0.5);
                }
                $precio = (float) ($datos['precio'] ?? 0);
                return ['id' => $cargo->id, 'concepto' => $datos['nombre'] ?? 'Sin descripción', 'codigo' => $datos['codigo'] ?? '',
                    'tipo' => $datos['tipo'] ?? '', 'fecha' => $datos['inicio'] ?? null, 'cantidad' => $cantidad,
                    'unidad' => ! empty($datos['porHora']) ? 'hora' : ($datos['unidad'] ?? ''), 'precio' => $precio,
                    'importe' => round($precio * $cantidad, 2)];
            })->values();
            $total = round($detalles->sum('importe'), 2);
            return ['id' => $cuenta->id, 'codigo' => $cuenta->codigo_atencion, 'emergenciaId' => $cuenta->emergencia_id,
                'paciente' => $paciente, 'documento' => $cuenta->ci ?: ($identidad['documento'] ?? ''),
                'origen' => 'Emergencias', 'fecha' => $cuenta->iniciada_en, 'atencionFinalizada' => $cuenta->finalizada_en !== null,
                'estado' => $cuenta->estado, 'moneda' => $cuenta->moneda, 'total' => $total, 'detalles' => $detalles];
        });

        return response()->json(['data' => $resultado])->header('Cache-Control', 'no-store');
    }
}
