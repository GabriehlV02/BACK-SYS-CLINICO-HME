<?php

namespace App\Flows\Pacientes\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EstudiosController
{
    private const TIPOS = ['LABORATORIO', 'TOMOGRAFIA', 'ECOGRAFIA', 'ECOCARDIOGRAMA'];
    private const ESTADOS = ['SOLICITADO', 'EN_PROCESO', 'REALIZADO', 'INFORMADO', 'ENTREGADO', 'CANCELADO'];

    public function index(Request $request, string $paciente): JsonResponse
    {
        $estudios = DB::table('estudios')->where('paciente_id', $paciente)->when($request->filled('tipo'), fn ($query) => $query->where('tipo', $request->string('tipo')))->latest('solicitado_en')->get()->map(fn (object $estudio) => $this->adjuntarContenido($estudio));
        return response()->json(['data' => $estudios]);
    }
    public function store(Request $request, string $paciente): JsonResponse
    {
        abort_unless(DB::table('pacientes')->where('id', $paciente)->exists(), 404);
        $data = $request->validate(['tipo' => ['required', Rule::in(self::TIPOS)], 'nombre' => ['required', 'string', 'max:180'], 'atencion_id' => ['nullable', 'uuid', Rule::exists('atenciones', 'id')], 'indicaciones' => ['nullable', 'string'], 'solicitado_por' => ['nullable', 'string', 'max:120'], 'estado_detalle' => ['nullable', 'string', 'max:50'], 'contenido' => ['nullable', 'array'], 'archivos' => ['nullable', 'array'], 'observaciones_tecnicas' => ['nullable', 'string'], 'orthanc_study_id' => ['nullable', 'string', 'max:100'], 'dicom_study_uid' => ['nullable', 'string', 'max:150']]);
        $id = (string) Str::uuid(); $ahora = now(); $tabla = $this->tabla($data['tipo']);
        DB::transaction(function () use ($data, $paciente, $id, $ahora, $tabla): void {
            DB::table('estudios')->insert(['id' => $id, 'paciente_id' => $paciente, 'atencion_id' => $data['atencion_id'] ?? null, 'codigo' => 'EST-'.strtoupper(Str::random(9)), 'tipo' => $data['tipo'], 'nombre' => $data['nombre'], 'estado' => 'SOLICITADO', 'estado_detalle' => $data['estado_detalle'] ?? null, 'indicaciones' => $data['indicaciones'] ?? null, 'solicitado_por' => $data['solicitado_por'] ?? null, 'solicitado_en' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora]);
            $detalle = ['id' => (string) Str::uuid(), 'estudio_id' => $id, 'contenido' => isset($data['contenido']) ? json_encode($data['contenido']) : null, 'archivos' => isset($data['archivos']) ? json_encode($data['archivos']) : null, 'observaciones_tecnicas' => $data['observaciones_tecnicas'] ?? null, 'created_at' => $ahora, 'updated_at' => $ahora];
            if ($data['tipo'] === 'TOMOGRAFIA') { $detalle['orthanc_study_id'] = $data['orthanc_study_id'] ?? null; $detalle['dicom_study_uid'] = $data['dicom_study_uid'] ?? null; }
            DB::table($tabla)->insert($detalle);
        });
        return response()->json(['data' => $this->adjuntarContenido(DB::table('estudios')->find($id))], 201);
    }
    public function actualizar(Request $request, string $estudio): JsonResponse
    {
        $actual = DB::table('estudios')->where('id', $estudio)->first(); abort_unless($actual, 404);
        $data = $request->validate(['estado' => ['sometimes', Rule::in(self::ESTADOS)], 'estado_detalle' => ['nullable', 'string', 'max:50'], 'informe' => ['nullable', 'string'], 'informado_por' => ['nullable', 'string', 'max:120'], 'contenido' => ['nullable', 'array'], 'archivos' => ['nullable', 'array'], 'observaciones_tecnicas' => ['nullable', 'string']]);
        $ahora = now(); $cambios = collect($data)->only(['estado', 'estado_detalle', 'informe', 'informado_por'])->all(); $cambios['updated_at'] = $ahora;
        if (($data['estado'] ?? null) === 'REALIZADO') $cambios['realizado_en'] = $ahora;
        if (($data['estado'] ?? null) === 'INFORMADO') $cambios['informado_en'] = $ahora;
        if (($data['estado'] ?? null) === 'ENTREGADO') $cambios['entregado_en'] = $ahora;
        DB::transaction(function () use ($actual, $estudio, $data, $cambios, $ahora): void { DB::table('estudios')->where('id', $estudio)->update($cambios); if (array_key_exists('contenido', $data) || array_key_exists('archivos', $data) || array_key_exists('observaciones_tecnicas', $data)) DB::table($this->tabla($actual->tipo))->where('estudio_id', $estudio)->update(array_filter(['contenido' => array_key_exists('contenido', $data) ? json_encode($data['contenido']) : null, 'archivos' => array_key_exists('archivos', $data) ? json_encode($data['archivos']) : null, 'observaciones_tecnicas' => $data['observaciones_tecnicas'] ?? null, 'updated_at' => $ahora], fn ($valor, $clave) => $clave === 'updated_at' || $valor !== null, ARRAY_FILTER_USE_BOTH)); });
        return response()->json(['data' => $this->adjuntarContenido(DB::table('estudios')->find($estudio))]);
    }
    private function tabla(string $tipo): string { return 'estudios_'.strtolower($tipo); }
    private function adjuntarContenido(object $estudio): object { $detalle = DB::table($this->tabla($estudio->tipo))->where('estudio_id', $estudio->id)->first(); $estudio->contenido = $detalle ? json_decode($detalle->contenido ?? 'null', true) : null; $estudio->archivos = $detalle ? json_decode($detalle->archivos ?? '[]', true) : []; $estudio->observaciones_tecnicas = $detalle->observaciones_tecnicas ?? null; if ($estudio->tipo === 'TOMOGRAFIA' && $detalle) { $estudio->orthanc_study_id = $detalle->orthanc_study_id; $estudio->dicom_study_uid = $detalle->dicom_study_uid; } return $estudio; }
}
