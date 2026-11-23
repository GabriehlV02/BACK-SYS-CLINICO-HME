<?php

namespace App\Flows\Pacientes\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentosHistoriaClinicaController
{
    public function guardarHojaClinica(Request $request, string $paciente): JsonResponse
    {
        abort_unless(DB::table('pacientes')->where('id', $paciente)->exists(), 404);
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'tipo' => ['required', 'in:hoja_atencion_emergencia,hoja_consulta_ambulatoria'],
            'titulo' => ['required', 'string', 'max:180'],
            'referencia_id' => ['required', 'string', 'max:100'],
        ]);

        $archivo = $datos['archivo'];
        $ruta = $archivo->store("historias-clinicas/{$paciente}/atenciones", 'local');
        abort_unless($ruta, 500, 'No se pudo guardar la hoja clínica.');
        $anterior = DB::table('documentos_historia_clinica')
            ->where('paciente_id', $paciente)->where('tipo', $datos['tipo'])
            ->where('referencia_id', $datos['referencia_id'])->first();

        try {
            if ($anterior) {
                DB::table('documentos_historia_clinica')->where('id', $anterior->id)->update([
                    'titulo' => $datos['titulo'], 'nombre_archivo' => $archivo->getClientOriginalName(),
                    'ruta' => $ruta, 'mime' => $archivo->getMimeType() ?: 'application/pdf', 'updated_at' => now(),
                ]);
                if ($anterior->ruta !== $ruta) Storage::disk('local')->delete($anterior->ruta);
                $id = $anterior->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('documentos_historia_clinica')->insert([
                'id' => $id, 'paciente_id' => $paciente, 'emergencia_id' => null,
                    'referencia_id' => $datos['referencia_id'], 'tipo' => $datos['tipo'], 'titulo' => $datos['titulo'],
                    'nombre_archivo' => $archivo->getClientOriginalName(), 'ruta' => $ruta,
                    'mime' => $archivo->getMimeType() ?: 'application/pdf', 'creado_por' => $request->user()?->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }

        return response()->json(['data' => DB::table('documentos_historia_clinica')->where('id', $id)->first([
            'id', 'paciente_id', 'emergencia_id', 'tipo', 'titulo', 'nombre_archivo', 'mime', 'periodo_desde', 'periodo_hasta', 'created_at',
        ])], $anterior ? 200 : 201);
    }

    public function guardarHojaConsulta(Request $request, int $cita): JsonResponse
    {
        $pacienteId = DB::table('citas')->where('id', $cita)->value('paciente_id');
        abort_unless($pacienteId, 404);
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'tipo' => ['required', 'in:hoja_consulta_ambulatoria'],
            'titulo' => ['required', 'string', 'max:180'],
        ]);
        $archivo = $datos['archivo'];
        $ruta = $archivo->store("historias-clinicas/{$pacienteId}/atenciones", 'local');
        abort_unless($ruta, 500, 'No se pudo guardar la hoja clínica.');
        $anterior = DB::table('documentos_historia_clinica')->where('paciente_id', $pacienteId)
            ->where('tipo', $datos['tipo'])->where('referencia_id', (string) $cita)->first();
        try {
            if ($anterior) {
                DB::table('documentos_historia_clinica')->where('id', $anterior->id)->update([
                    'titulo' => $datos['titulo'], 'nombre_archivo' => $archivo->getClientOriginalName(),
                    'ruta' => $ruta, 'mime' => $archivo->getMimeType() ?: 'application/pdf', 'updated_at' => now(),
                ]);
                Storage::disk('local')->delete($anterior->ruta);
                $id = $anterior->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('documentos_historia_clinica')->insert([
                    'id' => $id, 'paciente_id' => $pacienteId, 'emergencia_id' => null,
                    'referencia_id' => (string) $cita, 'tipo' => $datos['tipo'], 'titulo' => $datos['titulo'],
                    'nombre_archivo' => $archivo->getClientOriginalName(), 'ruta' => $ruta,
                    'mime' => $archivo->getMimeType() ?: 'application/pdf', 'creado_por' => $request->user()?->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }
        return response()->json(['data' => DB::table('documentos_historia_clinica')->where('id', $id)->first([
            'id', 'paciente_id', 'emergencia_id', 'tipo', 'titulo', 'nombre_archivo', 'mime', 'periodo_desde', 'periodo_hasta', 'created_at',
        ])], $anterior ? 200 : 201);
    }

    public function guardarReporteEmergencia(Request $request, string $emergencia): JsonResponse
    {
        $atencion = DB::table('emergencias')
            ->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('emergencias.id', $emergencia)
            ->first(['atenciones.paciente_id']);
        abort_unless($atencion, 404);
        abort_unless($atencion->paciente_id, 422, 'Vincula la atención con la ficha del paciente antes de guardar el reporte en su historia clínica.');

        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'titulo' => ['required', 'string', 'max:180'],
            'periodo_desde' => ['nullable', 'string', 'max:40'],
            'periodo_hasta' => ['nullable', 'string', 'max:40'],
        ]);

        $id = (string) Str::uuid();
        $archivo = $datos['archivo'];
        $ruta = $archivo->store("historias-clinicas/{$atencion->paciente_id}/emergencias", 'local');
        abort_unless($ruta, 500, 'No se pudo guardar el PDF del reporte.');

        try {
            DB::table('documentos_historia_clinica')->insert([
                'id' => $id, 'paciente_id' => $atencion->paciente_id, 'emergencia_id' => $emergencia,
                'referencia_id' => null, 'tipo' => 'reporte_emergencias', 'titulo' => $datos['titulo'],
                'nombre_archivo' => $archivo->getClientOriginalName(), 'ruta' => $ruta,
                'mime' => $archivo->getMimeType() ?: 'application/pdf',
                'periodo_desde' => $datos['periodo_desde'] ?? null, 'periodo_hasta' => $datos['periodo_hasta'] ?? null,
                'creado_por' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }

        return response()->json(['data' => DB::table('documentos_historia_clinica')->where('id', $id)->first(['id', 'paciente_id', 'emergencia_id', 'tipo', 'titulo', 'nombre_archivo', 'mime', 'periodo_desde', 'periodo_hasta', 'created_at'])], 201);

    }

    public function index(string $paciente): JsonResponse
    {
        return response()->json(['data' => DB::table('documentos_historia_clinica')
            ->where('paciente_id', $paciente)
            ->latest('created_at')
            ->get(['id', 'paciente_id', 'emergencia_id', 'tipo', 'titulo', 'nombre_archivo', 'mime', 'periodo_desde', 'periodo_hasta', 'created_at'])]);
    }

    public function archivo(string $documento)
    {
        $registro = DB::table('documentos_historia_clinica')->where('id', $documento)->first();
        abort_unless($registro && Storage::disk('local')->exists($registro->ruta), 404);

        return Storage::disk('local')->response($registro->ruta, $registro->nombre_archivo, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
