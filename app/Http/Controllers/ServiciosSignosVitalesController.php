<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServiciosSignosVitalesController extends Controller
{
    public function index(): JsonResponse
    {
        $servicios = DB::table('servicios_atencion_signos')->get();
        $asignados = DB::table('servicio_signo_vital')->join('configuracion_signos_vitales', 'configuracion_signos_vitales.id', '=', 'servicio_signo_vital.signo_id')->where('configuracion_signos_vitales.activo', true)->select('servicio_signo_vital.servicio_id', 'servicio_signo_vital.signo_id')->get()->groupBy('servicio_id');

        return response()->json(['data' => $servicios->map(fn ($servicio) => ['id' => $servicio->id, 'nombre' => $servicio->nombre, 'descripcion' => $servicio->descripcion, 'signos' => ($asignados[$servicio->id] ?? collect())->pluck('signo_id')->values()])]);
    }

    public function update(Request $request, string $servicio, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'SIGNOS_VITALES_REGISTRAR');
        $datos = $request->validate(['signos' => ['present', 'array', 'max:500'], 'signos.*' => ['required', 'string', 'distinct', Rule::exists('configuracion_signos_vitales', 'id')->where('activo', true)]]);
        DB::transaction(function () use ($servicio, $datos): void {
            abort_unless(DB::table('servicios_atencion_signos')->where('id', $servicio)->lockForUpdate()->exists(), 404);
            DB::table('servicio_signo_vital')->where('servicio_id', $servicio)->delete();
            foreach ($datos['signos'] as $signo) {
                DB::table('servicio_signo_vital')->insert(['servicio_id' => $servicio, 'signo_id' => $signo]);
            }
        });

        return response()->json(['message' => 'Asignaciones guardadas.', 'signos' => $datos['signos']]);
    }
}
