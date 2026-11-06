<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValoracionesSignosVitalesController extends Controller
{
    public function show(Request $request, int $cita, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $cita, $acceso);
        $valoracion = DB::table('valoraciones_signos_vitales')->where('cita_id', $cita)->orderByDesc('id')->first();

        return response()->json(['data' => $valoracion ? $this->presentar($valoracion) : null]);
    }

    public function store(Request $request, int $cita, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $cita, $acceso, true);
        $datos = $request->validate(['version' => ['present', 'nullable', 'integer', 'min:1'], 'valores' => ['required', 'array', 'min:1', 'max:500'], 'valores.*' => ['required', 'string', 'max:200']]);
        $definiciones = DB::table('configuracion_signos_vitales as s')->join('servicio_signo_vital as ss', 'ss.signo_id', '=', 's.id')
            ->where('ss.servicio_id', 'consulta-ambulatoria')->where('s.activo', true)->select('s.*')->get();
        $permitidos = [];
        foreach ($definiciones as $signo) {
            $componentes = $signo->componentes ? json_decode($signo->componentes, true, 512, JSON_THROW_ON_ERROR) : [];
            if ($componentes) {
                foreach ($componentes as $componente) {
                    $permitidos[$signo->id.'::'.$componente['id']] = ['nombre' => $signo->nombre.' · '.$componente['nombre'], 'unidad' => $signo->abreviatura, 'tipo' => 'Número decimal'];
                }
            } else {
                $permitidos[$signo->id] = ['nombre' => $signo->nombre, 'unidad' => $signo->abreviatura, 'tipo' => $signo->tipo];
            }
        }
        $mediciones = [];
        foreach ($datos['valores'] as $id => $valor) {
            $definicion = $permitidos[$id] ?? null;
            if (! $definicion || ! trim($valor)) {
                throw ValidationException::withMessages(['valores' => 'Solo se permiten mediciones activas y asignadas al servicio.']);
            }
            if ($definicion['tipo'] !== 'Texto' && (! is_numeric($valor) || ! is_finite((float) $valor) || ($definicion['tipo'] === 'Número entero' && floor((float) $valor) !== (float) $valor))) {
                throw ValidationException::withMessages(['valores' => 'Una medición no corresponde al tipo de dato configurado.']);
            }
            $mediciones[] = ['id' => $id, ...$definicion, 'valor' => trim($valor)];
        }
        $id = DB::transaction(function () use ($cita, $request, $mediciones, $datos): int {
            $citaActual = DB::table('citas')->where('id', $cita)->lockForUpdate()->first();
            abort_unless($citaActual, 404);
            abort_if($citaActual->estado === 'cancelada', 422, 'La cita está cancelada.');
            $ultimaVersion = DB::table('valoraciones_signos_vitales')->where('cita_id', $cita)->max('id');
            abort_if(($ultimaVersion === null ? null : (int) $ultimaVersion) !== ($datos['version'] === null ? null : (int) $datos['version']), 409, 'La valoración cambió. Vuelve a cargarla antes de guardar.');

            return DB::table('valoraciones_signos_vitales')->insertGetId(['cita_id' => $cita, 'registrado_por' => $request->user()->id, 'mediciones' => json_encode($mediciones, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });

        return response()->json(['data' => $this->presentar(DB::table('valoraciones_signos_vitales')->where('id', $id)->first())], 201);
    }

    private function autorizar(Request $request, int $cita, AccesoCompartido $acceso, bool $escritura = false): void
    {
        $usuarioId = $request->user()->id;
        $registro = DB::table('citas')->where('id', $cita)->first();
        abort_unless($registro, 404);
        if ($acceso->esAdministrador($usuarioId)) {
            return;
        }
        $permisos = $acceso->permisos($usuarioId);
        if (in_array('SIGNOS_VITALES_REGISTRAR', $permisos, true)) {
            return;
        }
        abort_unless(in_array('CONSULTAS_ATENDER', $permisos, true) || (! $escritura && in_array('CONSULTORIO_VER', $permisos, true)), 403);
        $esProfesional = $registro->profesional_id && DB::connection('usuarios')->table('profesionales')->where('id', $registro->profesional_id)->where('usuario_id', $usuarioId)->where('activo', true)->exists();
        abort_unless($esProfesional, 403);
    }

    private function presentar(object $valoracion): array
    {
        $mediciones = json_decode($valoracion->mediciones, true, 512, JSON_THROW_ON_ERROR);

        return ['id' => $valoracion->id, 'citaId' => (string) $valoracion->cita_id, 'registradoPor' => $valoracion->registrado_por, 'registradoEn' => $valoracion->created_at, 'mediciones' => $mediciones, 'valores' => collect($mediciones)->pluck('valor', 'id')];
    }
}
