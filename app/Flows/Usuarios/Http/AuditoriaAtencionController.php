<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditoriaAtencionController
{
    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        $acceso->exigir($usuario->id, 'USUARIOS_VER');
        $filtros = $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'paciente_id' => ['nullable', 'uuid'],
            'profesional_id' => ['nullable', 'uuid'],
            'servicio' => ['nullable', 'string', 'max:60'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $db = $acceso->conexion();
        $perPage = $filtros['per_page'] ?? 25;
        $page = $filtros['page'] ?? 1;
        $limit = $perPage * $page;
        $busqueda = trim($filtros['buscar'] ?? '');
        $pacientesCoincidentes = $busqueda === '' ? collect() : DB::table('pacientes')->where(fn (Builder $q) => $q->where('nombres', 'like', "%{$busqueda}%")
            ->orWhere('apellido_paterno', 'like', "%{$busqueda}%")->orWhere('apellido_materno', 'like', "%{$busqueda}%")
            ->orWhere('ci', 'like', "%{$busqueda}%"))->pluck('id');
        $eventosAuditoria = DB::table('auditorias_usuarios as a')->join('pacientes as p', 'p.id', '=', 'a.paciente_id')
            ->whereNotNull('a.paciente_id')->whereIn('a.categoria', ['atencion', 'pacientes', 'farmacia'])
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('a.created_at', '>=', $fecha))
            ->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('a.created_at', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.paciente_id', $id))
            ->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.usuario_id', $id))
            ->when($filtros['servicio'] ?? null, fn (Builder $q, string $servicio) => $q->where('a.servicio', $servicio))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->where('p.nombres', 'like', "%{$busqueda}%")
                ->orWhere('p.apellido_paterno', 'like', "%{$busqueda}%")->orWhere('p.apellido_materno', 'like', "%{$busqueda}%")
                ->orWhere('p.ci', 'like', "%{$busqueda}%")))
            ->orderByDesc('a.created_at')->limit($limit)->get([
                'a.id as origen_id',
                'a.usuario_id as profesional_id', 'a.paciente_id', 'a.servicio', 'a.created_at as fecha_hora',
                'p.nombres as paciente_nombres', 'p.apellido_paterno as paciente_apellido_paterno', 'p.apellido_materno as paciente_apellido_materno', 'p.ci as paciente_ci',
            ]);

        $ventas = $db->table('carritos_venta as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('usuarios_sistema as u', 'u.id', '=', 'v.usuario_sistema_id')
            ->where('v.estado', 'COMPLETADA')->whereNotNull('c.paciente_id')
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '>=', $fecha))
            ->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('c.paciente_id', $id))
            ->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('v.usuario_sistema_id', $id))
            ->when(isset($filtros['servicio']) && $filtros['servicio'] !== 'Ventas', fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->whereIn('c.paciente_id', $pacientesCoincidentes)
                ->orWhere('v.codigo', 'like', "%{$busqueda}%")))
            ->orderByDesc('v.cobrada_en')->limit($limit)->get([
                'v.id as origen_id',
                'v.usuario_sistema_id as profesional_id', 'c.paciente_id', 'v.cobrada_en as fecha_hora',
            ])->map(fn (object $venta): object => (object) [...(array) $venta, 'servicio' => 'Ventas']);

        $estancias = DB::table('estancia_profesionales as ep')->join('estancias_atencion as e', 'e.id', '=', 'ep.estancia_atencion_id')
            ->join('atenciones as a', 'a.id', '=', 'e.atencion_id')->join('pacientes as p', 'p.id', '=', 'a.paciente_id')
            ->whereNotNull('ep.usuario_id')->whereNotNull('a.paciente_id')
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('ep.asignado_en', '>=', $fecha))
            ->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('ep.asignado_en', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.paciente_id', $id))
            ->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('ep.usuario_id', $id))
            ->when($filtros['servicio'] ?? null, fn (Builder $q, string $servicio) => $q->where('e.area', $this->areaParaServicio($servicio)))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->where('p.nombres', 'like', "%{$busqueda}%")
                ->orWhere('p.apellido_paterno', 'like', "%{$busqueda}%")->orWhere('p.apellido_materno', 'like', "%{$busqueda}%")
                ->orWhere('p.ci', 'like', "%{$busqueda}%")))
            ->orderByDesc('ep.asignado_en')->limit($limit)->get([
                'ep.id as origen_id',
                'ep.usuario_id as profesional_id', 'a.paciente_id', 'e.area as area', 'ep.asignado_en as fecha_hora',
                'p.nombres as paciente_nombres', 'p.apellido_paterno as paciente_apellido_paterno', 'p.apellido_materno as paciente_apellido_materno', 'p.ci as paciente_ci',
            ])->map(fn (object $estancia): object => (object) [...(array) $estancia, 'servicio' => $this->servicioDesdeArea($estancia->area)]);

        $totalAuditoria = DB::table('auditorias_usuarios as a')->join('pacientes as p', 'p.id', '=', 'a.paciente_id')->whereNotNull('a.paciente_id')->whereIn('a.categoria', ['atencion', 'pacientes', 'farmacia'])
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('a.created_at', '>=', $fecha))->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('a.created_at', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.paciente_id', $id))->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.usuario_id', $id))
            ->when($filtros['servicio'] ?? null, fn (Builder $q, string $servicio) => $q->where('a.servicio', $servicio))->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->where('p.nombres', 'like', "%{$busqueda}%")->orWhere('p.apellido_paterno', 'like', "%{$busqueda}%")->orWhere('p.apellido_materno', 'like', "%{$busqueda}%")->orWhere('p.ci', 'like', "%{$busqueda}%")))->count();
        $totalVentas = $db->table('carritos_venta as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')->where('v.estado', 'COMPLETADA')->whereNotNull('c.paciente_id')
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '>=', $fecha))->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('v.cobrada_en', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('c.paciente_id', $id))->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('v.usuario_sistema_id', $id))
            ->when(isset($filtros['servicio']) && $filtros['servicio'] !== 'Ventas', fn (Builder $q) => $q->whereRaw('1 = 0'))->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->whereIn('c.paciente_id', $pacientesCoincidentes)->orWhere('v.codigo', 'like', "%{$busqueda}%")))->count();
        $totalEstancias = DB::table('estancia_profesionales as ep')->join('estancias_atencion as e', 'e.id', '=', 'ep.estancia_atencion_id')->join('atenciones as a', 'a.id', '=', 'e.atencion_id')->join('pacientes as p', 'p.id', '=', 'a.paciente_id')->whereNotNull('ep.usuario_id')->whereNotNull('a.paciente_id')
            ->when($filtros['fecha_desde'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('ep.asignado_en', '>=', $fecha))->when($filtros['fecha_hasta'] ?? null, fn (Builder $q, string $fecha) => $q->whereDate('ep.asignado_en', '<=', $fecha))
            ->when($filtros['paciente_id'] ?? null, fn (Builder $q, string $id) => $q->where('a.paciente_id', $id))->when($filtros['profesional_id'] ?? null, fn (Builder $q, string $id) => $q->where('ep.usuario_id', $id))
            ->when($filtros['servicio'] ?? null, fn (Builder $q, string $servicio) => $q->where('e.area', $this->areaParaServicio($servicio)))->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $f) => $f->where('p.nombres', 'like', "%{$busqueda}%")->orWhere('p.apellido_paterno', 'like', "%{$busqueda}%")->orWhere('p.apellido_materno', 'like', "%{$busqueda}%")->orWhere('p.ci', 'like', "%{$busqueda}%")))->count();

        $eventos = $eventosAuditoria->concat($ventas)->concat($estancias)->sortByDesc('fecha_hora')->values();
        $datosPacientes = DB::table('pacientes')->whereIn('id', $eventos->pluck('paciente_id')->unique())->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'ci'])->keyBy('id');
        $eventos->each(function (object $evento) use ($datosPacientes): void {
            $paciente = $datosPacientes->get($evento->paciente_id);
            $evento->paciente_nombres = $paciente->nombres ?? '';
            $evento->paciente_apellido_paterno = $paciente->apellido_paterno ?? '';
            $evento->paciente_apellido_materno = $paciente->apellido_materno ?? '';
            $evento->paciente_ci = $paciente->ci ?? null;
        });
        $ids = $eventos->pluck('profesional_id')->filter()->unique()->values();
        $personal = $db->table('usuarios_sistema')->whereIn('id', $ids)->get(['id', 'nombres', 'apellidos', 'usuario', 'rol'])->keyBy('id');
        $catalogo = $db->table('profesionales')->whereIn('usuario_id', $ids)->get(['usuario_id', 'tipo', 'rol'])->keyBy('usuario_id');
        $eventos = $eventos->map(function (object $evento) use ($personal, $catalogo): array {
            $persona = $personal->get($evento->profesional_id);
            $profesional = $catalogo->get($evento->profesional_id);

            return [
                'id' => $evento->origen_id,
                'profesional_id' => $evento->profesional_id,
                'profesional' => $persona ? trim($persona->nombres.' '.$persona->apellidos) : 'Personal clínico',
                'cargo' => $profesional->tipo ?? $profesional->rol ?? $persona->rol ?? 'Personal',
                'paciente_id' => $evento->paciente_id,
                'paciente' => trim($evento->paciente_nombres.' '.$evento->paciente_apellido_paterno.' '.$evento->paciente_apellido_materno),
                'ci' => $evento->paciente_ci,
                'servicio' => $evento->servicio,
                'fecha_hora' => $evento->fecha_hora,
            ];
        });
        $total = $totalAuditoria + $totalVentas + $totalEstancias;
        $paginaEventos = $eventos->slice(($page - 1) * $perPage, $perPage)->values();

        $opcionesPersonal = $db->table('usuarios_sistema as u')->leftJoin('profesionales as pr', 'pr.usuario_id', '=', 'u.id')
            ->where('u.tipo_usuario', 'EMPRESA')->where('u.estado', 'ACTIVO')
            ->select('u.id', 'u.nombres', 'u.apellidos', 'u.rol', 'pr.tipo')->distinct()->orderBy('u.nombres')->get();
        $pacientesConActividad = DB::table('auditorias_usuarios')->whereNotNull('paciente_id')->distinct()->pluck('paciente_id')
            ->concat(DB::table('atenciones as a')->join('estancias_atencion as e', 'e.atencion_id', '=', 'a.id')->whereNotNull('a.paciente_id')->distinct()->pluck('a.paciente_id'))
            ->concat($db->table('carritos_venta as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')->where('v.estado', 'COMPLETADA')->whereNotNull('c.paciente_id')->distinct()->pluck('c.paciente_id'))
            ->unique()->values();
        $opcionesPacientes = DB::table('pacientes as p')->whereIn('p.id', $pacientesConActividad)
            ->select('p.id', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 'p.ci')->orderBy('p.apellido_paterno')->get();

        return response()->json([
            'data' => $paginaEventos, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total,
            'profesionales' => $opcionesPersonal->map(fn (object $persona): array => ['id' => $persona->id, 'nombre' => trim($persona->nombres.' '.$persona->apellidos), 'cargo' => $persona->tipo ?? $persona->rol ?? 'Personal']),
            'pacientes' => $opcionesPacientes->map(fn (object $paciente): array => ['id' => $paciente->id, 'nombre' => trim($paciente->nombres.' '.$paciente->apellido_paterno.' '.$paciente->apellido_materno), 'ci' => $paciente->ci]),
            'servicios' => ['Emergencias', 'Consulta ambulatoria', 'Internación', 'Quirófano', 'Recuperación', 'Terapia intensiva', 'Estudios', 'Farmacia', 'Registro de pacientes', 'Ventas'],
        ])->header('Cache-Control', 'no-store');
    }

    private function areaParaServicio(string $servicio): string
    {
        return match ($servicio) {
            'Emergencias' => 'EMERGENCIA', 'Consulta ambulatoria' => 'CONSULTORIO', 'Internación' => 'INTERNACION',
            'Quirófano' => 'QUIROFANO', 'Recuperación' => 'RECUPERACION', 'Terapia intensiva' => 'TERAPIA_INTENSIVA', default => 'SIN_AREA',
        };
    }

    private function servicioDesdeArea(string $area): string
    {
        return match ($area) {
            'EMERGENCIA' => 'Emergencias', 'CONSULTORIO' => 'Consulta ambulatoria', 'INTERNACION' => 'Internación',
            'QUIROFANO' => 'Quirófano', 'RECUPERACION' => 'Recuperación', 'TERAPIA_INTENSIVA' => 'Terapia intensiva', default => 'Atención clínica',
        };
    }
}
