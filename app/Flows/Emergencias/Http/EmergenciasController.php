<?php

namespace App\Flows\Emergencias\Http;

use App\Flows\Farmacia\CatalogoCompartido;
use App\Flows\Pacientes\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmergenciasController
{
    private const CUBICULOS = ['1', '2', '3', '4', '5', '6', '7', '8'];

    public function index(): JsonResponse
    {
        $cuentas = DB::table('emergencias')
            ->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('atenciones.tipo', 'emergencia')
            ->orderByDesc('atenciones.iniciada_en')
            ->get(['emergencias.id', 'emergencias.identidad_provisional', 'emergencias.datos_ingreso', 'atenciones.paciente_id', 'atenciones.iniciada_en', 'atenciones.finalizada_en'])
            ->map(fn (object $emergencia): array => $this->presentar($emergencia))
            ->values();

        return response()->json(['cuentas' => $cuentas, 'ahora' => now()->toIso8601String()]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cubiculo' => ['required', 'string', Rule::in(self::CUBICULOS)],
            'identidad' => ['nullable', 'array'],
            'horaIngreso' => ['nullable', 'date_format:H:i'],
            'inicio' => ['nullable', 'date'],
            'solicitudId' => ['nullable', 'uuid'],
        ]);

        $emergencia = DB::transaction(function () use ($datos): object {
            if ($this->cubiculoOcupado($datos['cubiculo'])) {
                throw ValidationException::withMessages(['cubiculo' => 'El cubículo seleccionado está ocupado. Libéralo o da de alta al paciente antes de usarlo nuevamente.']);
            }
            $documento = $this->normalizarDocumento($datos['identidad']['documento'] ?? '');
            if ($documento !== '' && $this->pacienteOcupado($documento)) {
                throw ValidationException::withMessages(['identidad.documento' => 'Este CI ya tiene una atención de emergencia activa. No se puede registrar al mismo paciente dos veces.']);
            }
            $atencionId = (string) Str::uuid();
            $emergenciaId = (string) Str::uuid();
            $codigo = 'EMG-'.strtoupper(Str::substr(Str::uuid()->toString(), 0, 8));
            $inicio = isset($datos['inicio'])
                ? Carbon::parse($datos['inicio'])->setTimezone(config('app.timezone'))
                : (isset($datos['horaIngreso']) ? now()->setTimeFromTimeString($datos['horaIngreso']) : now());

            DB::table('atenciones')->insert([
                'id' => $atencionId,
                'paciente_id' => $this->pacientePorCi($documento),
                'tipo' => 'emergencia',
                'codigo_atencion' => $codigo,
                'estado' => 'en_curso',
                'iniciada_en' => $inicio,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('emergencias')->insert([
                'id' => $emergenciaId,
                'atencion_id' => $atencionId,
                'codigo_emergencia' => $codigo,
                'ubicacion_activa' => $datos['cubiculo'],
                'paciente_activo_ci' => $documento ?: null,
                'identidad_provisional' => isset($datos['identidad']) ? json_encode($datos['identidad']) : null,
                'datos_ingreso' => json_encode(['cubiculo' => $datos['cubiculo']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (object) [
                'id' => $emergenciaId,
                'paciente_id' => $this->pacientePorCi($documento),
                'identidad_provisional' => isset($datos['identidad']) ? json_encode($datos['identidad']) : null,
                'datos_ingreso' => json_encode(['cubiculo' => $datos['cubiculo']]),
                'iniciada_en' => $inicio,
                'finalizada_en' => null,
            ];
        });

        return response()->json($this->presentar($emergencia), 201);
    }

    public function catalogo(CatalogoCompartido $catalogo): JsonResponse
    {
        return response()->json($catalogo->obtener());
    }

    public function evaluacion(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'campos' => ['required', 'array'],
            'campos.*' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos): array {
            $evaluaciones = $ingreso['evaluaciones'] ?? [];
            $revision = count($evaluaciones) + 1;
            $evaluaciones[] = [
                'id' => (string) Str::uuid(), 'revision' => $revision, 'fecha' => now()->toIso8601String(),
                'usuario' => 'Profesional de turno', 'campos' => $datos['campos'],
            ];
            $ingreso['evaluaciones'] = $evaluaciones;

            return $ingreso;
        });
    }

    public function orden(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['medicamento', 'servicio', 'laboratorio', 'imagenologia', 'internacion', 'cuidado'])],
            'titulo' => ['required', 'string', 'max:200'],
            'indicaciones' => ['nullable', 'string', 'max:5000'],
            'dosis' => ['nullable', 'string', 'max:200'], 'via' => ['nullable', 'string', 'max:200'],
            'frecuencia' => ['nullable', 'string', 'max:200'], 'duracion' => ['nullable', 'string', 'max:200'],
            'itemId' => ['nullable', 'string', 'max:100'], 'porHora' => ['nullable', 'boolean'],
        ]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar órdenes.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos): array {
            $ordenes = $ingreso['ordenes'] ?? [];
            $ordenes[] = [...$datos, 'id' => (string) Str::uuid(), 'revision' => 1, 'fecha' => now()->toIso8601String(), 'usuario' => 'Profesional de turno', 'estado' => 'pendiente', 'aplicaciones' => [], 'historial' => []];
            $ingreso['ordenes'] = $ordenes;

            return $ingreso;
        });
    }

    public function usoEnfermeria(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['cuidado', 'procedimiento', 'insumo'])],
            'detalle' => ['required', 'string', 'max:500'],
            'cantidad' => ['nullable', 'numeric', 'gt:0'],
            'unidad' => ['nullable', 'string', 'max:80'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar actividades.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos): array {
            $registros = $ingreso['registrosEnfermeria'] ?? [];
            $registros[] = [...$datos, 'id' => (string) Str::uuid(), 'fecha' => now()->toIso8601String(), 'usuario' => 'Enfermería'];
            $ingreso['registrosEnfermeria'] = $registros;

            return $ingreso;
        });
    }

    public function consumo(Request $request, string $emergencia, CatalogoCompartido $catalogo): JsonResponse
    {
        $datos = $request->validate(['itemId' => ['required', 'string', 'max:100'], 'cantidad' => ['nullable', 'numeric', 'gt:0'], 'porHora' => ['required', 'boolean'], 'registroId' => ['required', 'uuid'], 'ordenId' => ['nullable', 'string', 'max:100']]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');
        $item = collect($catalogo->obtener())->firstWhere('id', $datos['itemId']);
        abort_unless($item, 422, 'El servicio seleccionado ya no está disponible en el catálogo.');
        abort_if($datos['porHora'] && ! preg_match('/^(hora|horas|hr|hrs)$/i', trim((string) $item['unidad'])), 422, 'Este ítem no tiene tarifa por hora.');
        $consumo = [...$item, 'itemId' => $item['id'], 'registroId' => $datos['registroId'], 'cantidad' => (float) ($datos['cantidad'] ?? 1), 'porHora' => $datos['porHora'], 'inicio' => now()->toIso8601String(), 'fin' => null];
        if (! empty($datos['ordenId'])) {
            $consumo['ordenId'] = $datos['ordenId'];
        }

        return $this->actualizar($emergencia, function (array $ingreso) use ($consumo): array {
            $ingreso['consumos'] ??= [];
            $ingreso['consumos'][] = $consumo;

            return $ingreso;
        });
    }

    public function finalizarConsumo(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate(['registroId' => ['required', 'uuid']]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos): array {
            $encontrado = false;
            foreach ($ingreso['consumos'] ?? [] as &$consumo) {
                if (($consumo['registroId'] ?? null) === $datos['registroId'] && ($consumo['porHora'] ?? false) && empty($consumo['fin'])) {
                    $consumo['fin'] = now()->toIso8601String();
                    $encontrado = true;
                    break;
                }
            }
            unset($consumo);
            abort_unless($encontrado, 422, 'El contador ya está pausado o no existe.');

            return $ingreso;
        });
    }

    public function signos(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'valores' => ['required', 'array', 'min:1'],
            'valores.*' => ['numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'hora' => ['required', 'date_format:H:i'],
        ]);

        $cuenta = $this->buscar($emergencia);
        abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar signos vitales.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos): array {
            $fecha = now()->setTimeFromTimeString($datos['hora']);
            $ingreso['signos'] ??= [];
            $ingreso['signos'][] = [
                'id' => (string) Str::uuid(),
                'fecha' => $fecha->toIso8601String(),
                'valores' => $datos['valores'],
                'observacion' => $datos['observacion'] ?? '',
            ];
            usort($ingreso['signos'], fn (array $a, array $b): int => strcmp($a['fecha'], $b['fecha']));

            return $ingreso;
        });
    }

    public function identidad(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $identidad = $request->except('revision');
        foreach ($identidad as $campo => $valor) {
            if (! is_string($valor) || mb_strlen($valor) > 200) {
                return response()->json(['message' => "El campo {$campo} no es válido."], 422);
            }
        }

        return DB::transaction(function () use ($emergencia, $identidad): JsonResponse {
            $cuenta = $this->buscar($emergencia);
            $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
            $ingreso['identidad'] = array_filter(array_merge($ingreso['identidad'] ?? [], $identidad), fn ($valor) => $valor !== '');
            $documento = $this->normalizarDocumento($ingreso['identidad']['documento'] ?? '');
            if ($documento !== '' && $this->pacienteOcupado($documento, $cuenta->id)) {
                throw ValidationException::withMessages(['documento' => 'Este CI ya tiene una atención de emergencia activa. No se puede registrar al mismo paciente dos veces.']);
            }
            DB::table('emergencias')->where('id', $cuenta->id)->update([
                'datos_ingreso' => json_encode($ingreso), 'paciente_activo_ci' => $documento ?: null, 'updated_at' => now(),
            ]);
            DB::table('atenciones')->where('id', $cuenta->atencion_id)->update([
                'paciente_id' => $this->pacientePorCi($documento), 'updated_at' => now(),
            ]);

            return response()->json($this->presentar($this->buscar($emergencia)));
        });
    }

    public function cierre(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'condicionEgreso' => ['required', Rule::in(['vivo', 'muerto'])],
            'causaEgreso' => ['required', Rule::in(['hospitalaria', 'solicitada', 'fuga', 'transferencia', 'deceso', 'internacion'])],
            'observacionEgreso' => ['nullable', 'string', 'max:3000'],
            'pago' => ['nullable', 'array'],
        ]);

        $cuenta = $this->buscar($emergencia);
        $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
        $fechaCierre = now()->toIso8601String();
        foreach ($ingreso['consumos'] ?? [] as &$consumo) {
            if (($consumo['porHora'] ?? false) && empty($consumo['fin'])) {
                $consumo['fin'] = $fechaCierre;
            }
        }
        unset($consumo);
        $ingreso['cierre'] = [...$datos, 'fecha' => $fechaCierre, 'usuario' => 'Profesional de turno'];
        DB::transaction(function () use ($cuenta, $ingreso): void {
            DB::table('emergencias')->where('id', $cuenta->id)->update(['datos_ingreso' => json_encode($ingreso), 'updated_at' => now()]);
            DB::table('emergencias')->where('id', $cuenta->id)->update(['ubicacion_activa' => null, 'paciente_activo_ci' => null]);
            DB::table('atenciones')->where('id', $cuenta->atencion_id)->update(['estado' => 'finalizada', 'finalizada_en' => now(), 'updated_at' => now()]);
        });

        return response()->json($this->presentar($this->buscar($emergencia)));
    }

    private function actualizar(string $id, callable $mutar): JsonResponse
    {
        $cuenta = $this->buscar($id);
        $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
        $ingreso = $mutar($ingreso);
        DB::table('emergencias')->where('id', $id)->update(['datos_ingreso' => json_encode($ingreso), 'updated_at' => now()]);

        return response()->json($this->presentar($this->buscar($id)));
    }

    private function cubiculoOcupado(string $cubiculo): bool
    {
        return DB::table('emergencias')
            ->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('atenciones.tipo', 'emergencia')
            ->whereNull('atenciones.finalizada_en')
            ->where(function ($consulta) use ($cubiculo): void {
                $consulta->where('emergencias.ubicacion_activa', $cubiculo)
                    ->orWhere('emergencias.datos_ingreso->cubiculo', $cubiculo);
            })
            ->exists();
    }

    private function pacienteOcupado(string $documento, ?string $exceptoId = null): bool
    {
        return DB::table('emergencias')
            ->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('atenciones.tipo', 'emergencia')
            ->whereNull('atenciones.finalizada_en')
            ->when($exceptoId, fn ($consulta) => $consulta->where('emergencias.id', '!=', $exceptoId))
            ->where(function ($consulta) use ($documento): void {
                $consulta->where('emergencias.paciente_activo_ci', $documento)
                    ->orWhere('emergencias.datos_ingreso->identidad->documento', $documento)
                    ->orWhere('emergencias.identidad_provisional->documento', $documento);
            })
            ->exists();
    }

    private function normalizarDocumento(mixed $documento): string
    {
        return is_string($documento) ? strtoupper(preg_replace('/[\s-]+/', '', trim($documento)) ?? '') : '';
    }

    private function buscar(string $id): object
    {
        return DB::table('emergencias')->join('atenciones', 'atenciones.id', '=', 'emergencias.atencion_id')
            ->where('emergencias.id', $id)
            ->first(['emergencias.id', 'emergencias.atencion_id', 'emergencias.identidad_provisional', 'emergencias.datos_ingreso', 'atenciones.paciente_id', 'atenciones.iniciada_en', 'atenciones.finalizada_en'])
            ?? abort(404, 'Atención de emergencia no encontrada.');
    }

    private function pacientePorCi(string $documento): ?string
    {
        if ($documento === '') {
            return null;
        }

        return Paciente::query()
            ->whereRaw("UPPER(REPLACE(REPLACE(ci, ' ', ''), '-', '')) = ?", [$documento])->value('id');
    }

    private function presentar(object $emergencia): array
    {
        $ingreso = json_decode($emergencia->datos_ingreso ?? '[]', true) ?: [];
        $identidad = $ingreso['identidad'] ?? json_decode($emergencia->identidad_provisional ?? 'null', true);

        return [
            'id' => $emergencia->id,
            'pacienteId' => $emergencia->paciente_id ?? null,
            'cubiculo' => $ingreso['cubiculo'] ?? '',
            'inicio' => $emergencia->iniciada_en,
            'fin' => $emergencia->finalizada_en,
            'revision' => crc32($emergencia->datos_ingreso ?? ''),
            'identidad' => $identidad,
            'signos' => $ingreso['signos'] ?? [],
            'consumos' => $ingreso['consumos'] ?? [],
            'evaluaciones' => $ingreso['evaluaciones'] ?? [],
            'ordenes' => $ingreso['ordenes'] ?? [],
            'registrosEnfermeria' => $ingreso['registrosEnfermeria'] ?? [],
            'cierre' => $ingreso['cierre'] ?? null,
        ];
    }
}
