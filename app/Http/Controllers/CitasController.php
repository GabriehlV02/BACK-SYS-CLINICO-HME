<?php

namespace App\Http\Controllers;

use App\Flows\Atenciones\ProfesionalesCompartidos;
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
    public function index(): JsonResponse
    {
        return response()->json($this->consulta()->orderBy('citas.fecha')->orderBy('citas.hora')->get()->map(fn ($cita) => $this->presentar($cita)));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'paciente_id' => ['required', 'uuid', 'exists:pacientes,id'],
            'profesional_id' => ['sometimes', 'nullable', 'uuid'],
            'doctor' => ['required', 'string', 'max:180'], 'especialidad' => ['required', 'string', 'max:120'],
            'consultorio' => ['required', 'string', 'max:100'], 'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'], 'estado' => ['required', Rule::in(['por_confirmar', 'confirmada'])],
        ]);
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
            'paciente' => trim($cita->nombres.' '.$cita->apellido_paterno.' '.$cita->apellido_materno),
            'nombre' => $cita->nombres, 'apellidos' => trim($cita->apellido_paterno.' '.$cita->apellido_materno),
            'ci' => $cita->ci, 'fechaNacimiento' => $cita->fecha_nacimiento,
            'edad' => (int) Carbon::parse($cita->fecha_nacimiento)->age,
            'domicilio' => $cita->direccion_domicilio, 'doctor' => $cita->doctor,
            'especialidad' => $cita->especialidad, 'consultorio' => $cita->consultorio,
            'fecha' => $cita->fecha, 'hora' => $cita->hora, 'estado' => $cita->estado];
    }
}
