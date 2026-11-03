<?php

namespace App\Flows\Usuarios\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RegistrarAccionAuditoria
{
    public function registrar(string $usuarioId, string $categoria, string $accion, string $metodo, string $ruta, ?string $ip, ?string $pacienteId = null, ?string $servicio = null): void
    {
        try {
            DB::table('auditorias_usuarios')->insert([
                'usuario_id' => $usuarioId,
                'categoria' => $categoria,
                'accion' => $accion,
                'metodo' => $metodo,
                'ruta' => mb_substr($ruta, 0, 255),
                'ip' => $ip,
                'paciente_id' => $pacienteId,
                'servicio' => $servicio,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            Log::warning('No se pudo guardar una acción de auditoría.', ['exception' => $error::class]);
        }
    }

    /** @return array{categoria: string, accion: string, ruta: string, paciente_id: ?string, servicio: ?string} */
    public function describir(Request $request): array
    {
        $ruta = $request->route()?->uri() ?? $request->path();
        $normalizada = mb_strtolower($ruta);
        $categoria = match (true) {
            str_contains($normalizada, 'ventas'), str_contains($normalizada, 'cobros') => 'ventas',
            str_contains($normalizada, 'farmacia') => 'farmacia',
            str_contains($normalizada, 'pacientes') => 'pacientes',
            str_contains($normalizada, 'emergencias'), str_contains($normalizada, 'atenciones'),
            str_contains($normalizada, 'estudios'), str_contains($normalizada, 'citas') => 'atencion',
            default => 'usuarios',
        };
        $verbo = match ($request->method()) {
            'POST' => 'Registró',
            'PUT', 'PATCH' => 'Actualizó',
            'DELETE' => 'Eliminó',
            default => 'Ejecutó',
        };
        $recurso = match ($categoria) {
            'ventas' => 'una operación de venta',
            'farmacia' => 'una operación de farmacia',
            'pacientes' => 'una operación de pacientes',
            'atencion' => 'una operación de atención clínica',
            default => 'una operación de usuarios o configuración',
        };

        $servicio = match (true) {
            str_contains($normalizada, 'emergencias') => 'Emergencias',
            str_contains($normalizada, 'internacion') => 'Internación',
            str_contains($normalizada, 'quirofano') => 'Quirófano',
            str_contains($normalizada, 'consultorio'), str_contains($normalizada, 'citas') => 'Consulta ambulatoria',
            str_contains($normalizada, 'estudios') => 'Estudios',
            str_contains($normalizada, 'farmacia') => 'Farmacia',
            str_contains($normalizada, 'pacientes') => 'Registro de pacientes',
            str_contains($normalizada, 'atenciones') => 'Atención clínica',
            default => null,
        };
        $pacienteId = $request->input('paciente_id') ?? $request->route('paciente');
        $servicioPorArea = $request->input('area') ? $this->servicioDesdeArea((string) $request->input('area')) : null;
        $servicio = $servicioPorArea ?? $servicio;
        $atencionId = $request->route('atencion');
        $emergenciaId = $request->route('emergencia');
        if (! $pacienteId && $atencionId) {
            $pacienteId = DB::table('atenciones')->where('id', $atencionId)->value('paciente_id');
            $area = DB::table('estancias_atencion')->where('atencion_id', $atencionId)->orderByDesc('ingresada_en')->value('area');
            $servicio ??= $this->servicioDesdeArea($area);
        }
        if (! $pacienteId && $emergenciaId) {
            $pacienteId = DB::table('emergencias')->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
                ->where('emergencias.id', $emergenciaId)->value('atenciones.paciente_id');
        }
        if (! $pacienteId && $request->route('estudio')) {
            $pacienteId = DB::table('estudios')->where('id', $request->route('estudio'))->value('paciente_id');
        }
        if (is_string($pacienteId) && ! Str::isUuid($pacienteId)) {
            $pacienteId = null;
        }

        return ['categoria' => $categoria, 'accion' => "$verbo $recurso", 'ruta' => $ruta, 'paciente_id' => $pacienteId, 'servicio' => $servicio];
    }

    private function servicioDesdeArea(?string $area): ?string
    {
        return match ($area) {
            'EMERGENCIA', 'EMERGENCIAS' => 'Emergencias', 'CONSULTORIO' => 'Consulta ambulatoria', 'INTERNACION' => 'Internación',
            'QUIROFANO' => 'Quirófano', 'RECUPERACION' => 'Recuperación', 'TERAPIA_INTENSIVA' => 'Terapia intensiva', default => null,
        };
    }
}
