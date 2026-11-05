<?php

namespace App\Http\Controllers;

use App\Flows\Atenciones\ProfesionalesCompartidos;
use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CitasController extends Controller
{
    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $consulta = $this->consulta();
        $usuario = $request->user();

        // Un médico solo puede conocer la agenda vinculada a su propio perfil.
        // Recepción y triaje no tienen perfil médico y conservan la agenda operativa.
        if ($usuario && ! $acceso->esAdministrador($usuario->id)) {
            $profesionalId = DB::connection('usuarios')->table('profesionales')
                ->where('usuario_id', $usuario->id)->where('activo', true)->where('tipo', 'Médico/a')->value('id');

            if ($profesionalId) {
                $consulta->where('citas.profesional_id', $profesionalId);
            }
        }

        return response()->json($consulta->orderBy('citas.fecha')->orderBy('citas.hora')->get()->map(fn ($cita) => $this->presentar($cita)));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'paciente_id' => ['required', 'uuid', 'exists:pacientes,id'],
            'profesional_id' => ['sometimes', 'nullable', 'uuid'],
            'servicio_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'doctor' => ['required', 'string', 'max:180'], 'especialidad' => ['required', 'string', 'max:120'],
            'consultorio' => ['required', 'string', 'max:100'], 'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'], 'estado' => ['required', Rule::in(['por_confirmar', 'confirmada'])],
        ]);
        if (! empty($datos['servicio_id'])) {
            $existe = DB::connection('usuarios')->table('servicios as s')
                ->join('tipos_inventario as t', 't.id', '=', 's.tipo_id')
                ->leftJoin('unidades_inventario as u', 'u.id', '=', 's.unidad_id')
                ->where('s.id', $datos['servicio_id'])->where('s.estado', 'ACTIVO')
                ->where('t.activo', true)->where(fn ($q) => $q->whereNull('s.unidad_id')->orWhere('u.activo', true))->exists();
            if (! $existe) {
                throw ValidationException::withMessages(['servicio_id' => 'Selecciona un servicio activo del catálogo contable.']);
            }
        }
        if (! empty($datos['profesional_id'])) {
            $profesional = app(ProfesionalesCompartidos::class)->buscar($datos['profesional_id'], 'Médico/a');
            $datos['doctor'] = trim($profesional->nombres.' '.$profesional->apellidos);
        }
        try {
            $id = DB::table('citas')->insertGetId([...$datos,
                'reserva_horario' => $datos['consultorio'].'|'.$datos['fecha'].'|'.$datos['hora'],
                'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['hora' => 'El consultorio ya tiene una cita en ese horario.']);
        }

        return response()->json($this->presentar($this->consulta()->where('citas.id', $id)->first()), 201);
    }

    public function estado(Request $request, string $cita): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', Rule::in(['confirmada', 'cancelada'])]]);
        $actual = $this->consulta()->where('citas.id', $cita)->first();
        abort_unless($actual, 404);
        if ($datos['estado'] === 'confirmada' && $actual->profesional_id) {
            app(ProfesionalesCompartidos::class)->buscar($actual->profesional_id, 'Médico/a');
        }
        abort_if($actual->estado === 'cancelada' && $datos['estado'] !== 'cancelada', 422, 'La cita fue cancelada. Registra una nueva cita.');
        DB::table('citas')->where('id', $cita)->update([...$datos, ...($datos['estado'] === 'cancelada' ? ['reserva_horario' => null] : []), 'updated_at' => now()]);

        return response()->json($this->presentar($this->consulta()->where('citas.id', $cita)->first()));
    }

    private function consulta(): Builder
    {
        return DB::table('citas')->join('pacientes', 'pacientes.id', '=', 'citas.paciente_id')
            ->select(['citas.*', 'pacientes.nombres', 'pacientes.apellido_paterno', 'pacientes.apellido_materno', 'pacientes.ci', 'pacientes.fecha_nacimiento', 'pacientes.direccion_domicilio']);
    }

    private function presentar(object $cita): array
    {
        return ['id' => (string) $cita->id, 'pacienteId' => $cita->paciente_id,
            'profesionalId' => $cita->profesional_id,
            'servicioId' => $cita->servicio_id,
            'paciente' => trim($cita->nombres.' '.$cita->apellido_paterno.' '.$cita->apellido_materno),
            'nombre' => $cita->nombres, 'apellidos' => trim($cita->apellido_paterno.' '.$cita->apellido_materno),
            'ci' => $cita->ci, 'fechaNacimiento' => $cita->fecha_nacimiento,
            'edad' => (int) Carbon::parse($cita->fecha_nacimiento)->age,
            'domicilio' => $cita->direccion_domicilio, 'doctor' => $cita->doctor,
            'especialidad' => $cita->especialidad, 'consultorio' => $cita->consultorio,
            'fecha' => $cita->fecha, 'hora' => $cita->hora, 'estado' => $cita->estado];
    }
}
