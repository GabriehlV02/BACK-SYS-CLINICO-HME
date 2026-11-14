<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AtencionConsultaController extends Controller
{
    private function autorizar(Request $request, int $cita, AccesoCompartido $acceso, bool $escritura = false): object
    {
        $registro = DB::table('citas')->where('id', $cita)->first();
        abort_unless($registro, 404);
        $usuario = $request->user()->id;
        if (! $acceso->esAdministrador($usuario)) {
            $permisos = $acceso->permisos($usuario);
            abort_unless(in_array('CONSULTAS_ATENDER', $permisos, true) || (! $escritura && in_array('CONSULTORIO_VER', $permisos, true)), 403);
            $asignado = $registro->profesional_id && DB::connection('usuarios')->table('profesionales')->where('id', $registro->profesional_id)->where('usuario_id', $usuario)->where('activo', true)->exists();
            abort_unless($asignado, 403);
        }
        return $registro;
    }

    public function show(Request $request, int $cita, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $cita, $acceso);
        $registro = DB::table('atenciones_consulta')->where('cita_id', $cita)->first();
        return response()->json(['data' => $registro ? $this->presentar($registro) : null]);
    }

    public function historial(Request $request, int $cita, AccesoCompartido $acceso): JsonResponse
    {
        $actual = $this->autorizar($request, $cita, $acceso);
        $registros = DB::table('atenciones_consulta as a')->join('citas as c', 'c.id', '=', 'a.cita_id')
            ->where('c.paciente_id', $actual->paciente_id)->whereNotNull('a.finalizada_en')
            ->where('c.id', '<>', $cita)
            ->where(function ($query) use ($actual): void {
                $query->where('c.fecha', '<', $actual->fecha)->orWhere(function ($mismoDia) use ($actual): void {
                    $mismoDia->where('c.fecha', '=', $actual->fecha)->where('c.hora', '<', $actual->hora);
                });
            })
            ->select(['a.cita_id', 'a.contenido', 'a.finalizada_en', 'c.fecha', 'c.hora', 'c.doctor', 'c.especialidad', 'c.tipo'])
            ->selectSub(DB::table('valoraciones_signos_vitales as v')->select('v.mediciones')->whereColumn('v.cita_id', 'c.id')->orderByDesc('v.id')->limit(1), 'mediciones')
            ->orderByDesc('c.fecha')->orderByDesc('c.hora')->orderByDesc('c.id')
            ->get();
        return response()->json(['data' => $registros->map(fn ($registro) => [
            'citaId' => (string) $registro->cita_id, 'fecha' => $registro->fecha, 'hora' => $registro->hora,
            'medico' => $registro->doctor, 'especialidad' => $registro->especialidad, 'tipo' => $registro->tipo,
            'signosVitales' => $registro->mediciones ? json_decode($registro->mediciones, true, 512, JSON_THROW_ON_ERROR) : [],
            'finalizadaEn' => $registro->finalizada_en, 'contenido' => json_decode($registro->contenido, true, 512, JSON_THROW_ON_ERROR),
        ])]);
    }

    public function guardar(Request $request, int $cita, AccesoCompartido $acceso): JsonResponse
    {
        $this->autorizar($request, $cita, $acceso, true);
        $datos = $request->validate([
            'version' => ['present', 'nullable', 'integer', 'min:1'],
            'finalizar' => ['required', 'boolean'],
            'contenido' => ['required', 'array:motivo,enfermedadActual,examenFisico,antecedentes,antecedentesEstructurados,notas,diagnosticos,revision,ordenes,documentos'],
            'contenido.motivo' => ['present', 'nullable', 'string', 'max:10000'],
            'contenido.enfermedadActual' => ['present', 'nullable', 'string', 'max:20000'],
            'contenido.examenFisico' => ['present', 'nullable', 'string', 'max:20000'],
            'contenido.antecedentes' => ['present', 'nullable', 'string', 'max:20000'],
            'contenido.antecedentesEstructurados' => ['sometimes', 'array:vivienda,serviciosBasicos,alimentacion,habitos,hipertension,diabetes,tuberculosis,chagas,cardiopatia,asma,epoc,enfermedadRenal,otroPatologico,quirurgicos,alergicos,familiares'],
            'contenido.antecedentesEstructurados.*' => ['string', 'max:5000'],
            'contenido.notas' => ['present', 'nullable', 'string', 'max:20000'],
            'contenido.diagnosticos' => ['present', 'array', 'max:100'],
            'contenido.diagnosticos.*' => ['array:diagnostico,planes'],
            'contenido.diagnosticos.*.diagnostico' => ['required', 'string', 'max:1000'],
            'contenido.diagnosticos.*.planes' => ['present', 'array', 'max:100'],
            'contenido.diagnosticos.*.planes.*' => ['string', 'max:5000'],
            'contenido.revision' => ['present', 'array', 'max:20'],
            'contenido.revision.*' => ['string', 'max:5000'],
            'contenido.ordenes' => ['present', 'array', 'max:100'],
            'contenido.ordenes.*' => ['string', 'max:1000'],
            'contenido.documentos' => ['sometimes', 'array:recetas,ordenes,resultados,reconsultas'],
            'contenido.documentos.recetas' => ['sometimes', 'array', 'max:100'],
            'contenido.documentos.ordenes' => ['sometimes', 'array', 'max:100'],
            'contenido.documentos.resultados' => ['sometimes', 'array', 'max:100'],
            'contenido.documentos.reconsultas' => ['sometimes', 'array', 'max:100'],
        ]);
        foreach (['motivo', 'enfermedadActual', 'examenFisico', 'antecedentes', 'notas'] as $campo) $datos['contenido'][$campo] ??= '';
        abort_if(strlen(json_encode($datos['contenido'], JSON_THROW_ON_ERROR)) > 1048576, 422, 'La atención excede el tamaño permitido.');
        $registro = DB::transaction(function () use ($cita, $request, $datos) {
            $citaActual = DB::table('citas')->where('id', $cita)->lockForUpdate()->first();
            abort_unless($citaActual, 404);
            abort_if($citaActual->estado === 'cancelada', 422, 'La cita está cancelada.');
            $actual = DB::table('atenciones_consulta')->where('cita_id', $cita)->first();
            abort_if(($actual ? (int) $actual->version : null) !== ($datos['version'] === null ? null : (int) $datos['version']), 409, 'La atención cambió. Vuelve a cargarla antes de guardar.');
            abort_if($actual?->finalizada_en, 409, 'La atención ya está finalizada.');
            $campos = ['contenido' => json_encode($datos['contenido'], JSON_THROW_ON_ERROR), 'registrado_por' => $request->user()->id, 'version' => ($actual?->version ?? 0) + 1, 'finalizada_en' => $datos['finalizar'] ? now() : null, 'updated_at' => now()];
            if ($actual) DB::table('atenciones_consulta')->where('id', $actual->id)->update($campos);
            else DB::table('atenciones_consulta')->insert(['cita_id' => $cita, 'created_at' => now(), ...$campos]);
            return DB::table('atenciones_consulta')->where('cita_id', $cita)->first();
        });
        return response()->json(['data' => $this->presentar($registro)]);
    }

    private function presentar(object $registro): array
    {
        return ['citaId' => (string) $registro->cita_id, 'version' => (int) $registro->version, 'contenido' => json_decode($registro->contenido, true, 512, JSON_THROW_ON_ERROR), 'finalizadaEn' => $registro->finalizada_en, 'actualizadaEn' => $registro->updated_at];
    }
}
