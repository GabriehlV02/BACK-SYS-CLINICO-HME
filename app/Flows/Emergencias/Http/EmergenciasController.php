<?php

namespace App\Flows\Emergencias\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Farmacia\CatalogoCompartido;
use App\Flows\Pacientes\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

            DB::table('cuentas_atencion_emergencia')->insert([
                'id' => (string) Str::uuid(), 'emergencia_id' => $emergenciaId,
                'estado' => 'abierta', 'moneda' => 'BOB', 'created_at' => now(), 'updated_at' => now(),
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

        $usuario = $request->user();
        $roles = $usuario ? app(AccesoCompartido::class)->roles((string) $usuario->id)->get(['r.codigo', 'r.nombre']) : collect();
        $esMedico = $roles->contains(fn (object $rol): bool => str_contains(mb_strtolower($rol->codigo.' '.$rol->nombre), 'medic') || str_contains(mb_strtolower($rol->codigo.' '.$rol->nombre), 'médic'));

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $usuario, $esMedico): array {
            $evaluaciones = $ingreso['evaluaciones'] ?? [];
            $revision = count($evaluaciones) + 1;
            $evaluaciones[] = [
                'id' => (string) Str::uuid(), 'revision' => $revision, 'fecha' => now()->toIso8601String(),
                'usuario' => trim(($usuario->nombres ?? '').' '.($usuario->apellidos ?? '')) ?: 'Profesional de turno',
                'autorId' => $usuario->id ?? null, 'autorTipo' => $esMedico ? 'medico' : 'otro', 'campos' => $datos['campos'],
            ];
            $ingreso['evaluaciones'] = $evaluaciones;

            return $ingreso;
        });
    }

    public function diagnosticos(string $emergencia): JsonResponse
    {
        $this->buscar($emergencia);

        return response()->json(DB::table('emergencia_diagnosticos as ed')
            ->join('diagnosticos as d', 'd.id', '=', 'ed.diagnostico_id')
            ->where('ed.emergencia_id', $emergencia)->orderBy('d.nombre')->get(['d.codigo', 'd.nombre']));
    }

    public function guardarDiagnostico(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'exists:diagnosticos,codigo'],
            'seleccionado' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($emergencia, $datos): JsonResponse {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');
            $clave = [
                'emergencia_id' => $emergencia,
                'diagnostico_id' => DB::table('diagnosticos')->where('codigo', $datos['codigo'])->value('id'),
            ];
            if ($datos['seleccionado']) {
                DB::table('emergencia_diagnosticos')->insertOrIgnore($clave);
            } else {
                DB::table('emergencia_diagnosticos')->where($clave)->delete();
            }

            return $this->diagnosticos($emergencia);
        });
    }

    public function documentacion(string $emergencia): JsonResponse
    {
        $this->buscar($emergencia);
        $registro = DB::table('emergencia_documentacion')->where('emergencia_id', $emergencia)->first();
        return response()->json(['anamnesis' => $registro ? json_decode($registro->anamnesis, true) : null, 'revision' => $registro ? json_decode($registro->revision, true) : null]);
    }

    public function guardarDocumentacion(Request $request, string $emergencia): JsonResponse
    {
        $reglas = [
            'seccion' => ['required', Rule::in(['anamnesis', 'revision'])],
            'datos' => ['required', 'array'],
            'datos.relato' => ['sometimes', 'string', 'max:20000'],
            'datos.impresion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'datos.fotos' => ['sometimes', 'array', 'max:3'],
            'datos.fotos.*.id' => ['required_with:datos.fotos', 'uuid'],
            'datos.fotos.*.nombre' => ['required_with:datos.fotos', 'string', 'max:255'],
            'datos.fotos.*.url' => ['required_with:datos.fotos', 'string', 'max:1066700', 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ];
        if ($request->input('seccion') === 'revision') $reglas['datos.*'] = ['string', 'max:5000'];
        $datos = $request->validate($reglas);
        if ($datos['seccion'] === 'revision') {
            foreach ($datos['datos'] as $clave => $valor) {
                abort_unless(is_string($clave) && preg_match('/^[a-z]{1,30}$/', $clave) && is_string($valor) && mb_strlen($valor) <= 5000, 422, 'Revisión por sistemas inválida.');
            }
        }
        return DB::transaction(function () use ($emergencia, $datos): JsonResponse {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');
            $columna = $datos['seccion'];
            $existe = DB::table('emergencia_documentacion')->where('emergencia_id', $emergencia)->exists();
            if ($existe) DB::table('emergencia_documentacion')->where('emergencia_id', $emergencia)->update([$columna => json_encode($datos['datos'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            else DB::table('emergencia_documentacion')->insert(['emergencia_id' => $emergencia, $columna => json_encode($datos['datos'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['message' => 'Documentación guardada.']);
        });
    }

    public function editarEvaluacion(Request $request, string $emergencia, string $evaluacion): JsonResponse
    {
        $datos = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'campos' => ['required', 'array'],
            'campos.*' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden editar evoluciones.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $evaluacion): array {
            $evaluaciones = $ingreso['evaluaciones'] ?? [];
            $indice = collect($evaluaciones)->search(fn (array $item): bool => ($item['id'] ?? null) === $evaluacion);
            abort_if($indice === false, 404, 'No se encontró la anotación seleccionada.');

            $actual = $evaluaciones[$indice];
            $actual['historialEdiciones'] ??= [];
            $actual['historialEdiciones'][] = [
                'fecha' => now()->toIso8601String(),
                'usuario' => 'Profesional de turno',
                'campos' => $actual['campos'] ?? [],
            ];
            $actual['campos'] = array_merge($actual['campos'] ?? [], $datos['campos']);
            $actual['editadoEn'] = now()->toIso8601String();
            $actual['editadoPor'] = 'Profesional de turno';
            $evaluaciones[$indice] = $actual;
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
        $usuario = $request->user();
        $roles = app(AccesoCompartido::class)->roles((string) $usuario->id)->get(['r.codigo', 'r.nombre']);
        $esMedico = $roles->contains(fn (object $rol): bool => str_contains(mb_strtolower($rol->codigo.' '.$rol->nombre), 'medic') || str_contains(mb_strtolower($rol->codigo.' '.$rol->nombre), 'médic'));
        abort_if($datos['tipo'] === 'internacion' && ! $esMedico, 403, 'Solo un médico puede emitir una orden de hospitalización o internación.');
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar órdenes.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $usuario, $esMedico): array {
            $ordenes = $ingreso['ordenes'] ?? [];
            $ordenes[] = [...$datos, 'id' => (string) Str::uuid(), 'revision' => 1, 'fecha' => now()->toIso8601String(), 'usuario' => trim(($usuario->nombres ?? '').' '.($usuario->apellidos ?? '')) ?: 'Profesional de turno', 'autorId' => $usuario->id, 'autorTipo' => $esMedico ? 'medico' : 'otro', 'estado' => 'pendiente', 'aplicaciones' => [], 'historial' => []];
            $ingreso['ordenes'] = $ordenes;

            return $ingreso;
        });
    }

    public function aplicarOrden(Request $request, string $emergencia, CatalogoCompartido $catalogo): JsonResponse
    {
        $datos = $request->validate([
            'ordenId' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'registroId' => ['required', 'uuid'], 'itemId' => ['required', 'string', 'max:100'],
            'cantidad' => ['required', 'numeric', 'gt:0'], 'observacion' => ['nullable', 'string', 'max:1000'],
        ]);
        $item = collect($catalogo->obtener())->firstWhere('id', $datos['itemId']);
        abort_unless($item, 422, 'El ítem seleccionado no está disponible en el catálogo.');

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $item, $request): array {
            $ordenes = $ingreso['ordenes'] ?? [];
            $indice = collect($ordenes)->search(fn (array $orden): bool => ($orden['id'] ?? null) === $datos['ordenId']);
            abort_if($indice === false, 404, 'No se encontró la indicación.');
            $orden = $ordenes[$indice];
            if (collect($orden['aplicaciones'] ?? [])->contains(fn (array $aplicacion): bool => ($aplicacion['consumoId'] ?? null) === $datos['registroId'])) return $ingreso;
            abort_if(($orden['revision'] ?? 0) !== $datos['revision'], 409, 'La indicación cambió. Actualiza antes de aplicarla.');
            abort_if(($orden['estado'] ?? '') === 'cancelada', 422, 'La indicación está suspendida.');
            abort_if(! empty($orden['itemId']) && $orden['itemId'] !== $datos['itemId'], 422, 'El ítem no corresponde a la indicación.');
            $porHora = (bool) ($orden['porHora'] ?? false);
            abort_if($porHora && ! preg_match('/^(hora|horas|hr|hrs)$/i', trim((string) ($item['unidad'] ?? ''))), 422, 'El ítem no tiene tarifa por hora.');
            $fecha = now()->toIso8601String();
            $ingreso['consumos'][] = [...$item, 'itemId' => $item['id'], 'registroId' => $datos['registroId'], 'ordenId' => $datos['ordenId'],
                'cantidad' => (float) $datos['cantidad'], 'porHora' => $porHora, 'inicio' => $fecha, 'fin' => null];
            $orden['aplicaciones'][] = ['id' => (string) Str::uuid(), 'fecha' => $fecha, 'usuario' => trim(($request->user()?->nombres ?? '').' '.($request->user()?->apellidos ?? '')) ?: 'Sin registrar',
                'observacion' => $datos['observacion'] ?? '', 'consumoId' => $datos['registroId']];
            $orden['estado'] = $porHora ? 'en_curso' : 'aplicada';
            $ordenes[$indice] = $orden;
            $ingreso['ordenes'] = $ordenes;

            return $ingreso;
        });
    }

    public function usoEnfermeria(Request $request, string $emergencia, CatalogoCompartido $catalogo, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['cuidado', 'procedimiento', 'insumo'])],
            'detalle' => ['required', 'string', 'max:500'],
            'cantidad' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'unidad' => ['nullable', 'string', 'max:80'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'itemId' => [Rule::requiredIf($request->input('tipo') === 'insumo'), 'nullable', 'string', 'max:100'],
            'almacenId' => [Rule::requiredIf($request->input('tipo') === 'insumo'), 'nullable', 'integer', 'min:1'],
            'solicitudId' => [Rule::requiredIf($request->input('tipo') === 'insumo'), 'nullable', 'uuid'],
        ]);
        abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar actividades.');
        $item = null;
        if ($datos['tipo'] === 'insumo') {
            $item = collect($catalogo->obtener())->firstWhere('id', $datos['itemId']);
            abort_unless($item && in_array($item['tipo'], ['insumo', 'producto'], true), 422, 'El insumo no está disponible en el catálogo.');
            abort_unless(! empty($datos['cantidad']), 422, 'La cantidad del insumo es obligatoria.');
            return $this->registrarConsumoInventario($request, $emergencia, $datos, $item, $acceso);
        }

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $item): array {
            $registros = $ingreso['registrosEnfermeria'] ?? [];
            $registroId = (string) Str::uuid();
            $registros[] = [...$datos, 'id' => $registroId, 'fecha' => now()->toIso8601String(), 'usuario' => 'Enfermería'];
            $ingreso['registrosEnfermeria'] = $registros;
            if ($item) {
                $ingreso['consumos'][] = [...$item, 'itemId' => $item['id'], 'registroId' => $registroId,
                    'cantidad' => (float) $datos['cantidad'], 'porHora' => false, 'inicio' => now()->toIso8601String(), 'fin' => null];
            }

            return $ingreso;
        });
    }

    public function recuperarConsumosInventario(AccesoCompartido $acceso): JsonResponse
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $pendientes = $db->table('clinico_consumos_inventario')->whereNull('sincronizado_en')
            ->when($permitidos !== null, fn ($query) => $query->whereIn('almacen_id', $permitidos))->orderBy('created_at')->limit(100)->get();
        foreach ($pendientes as $operacion) {
            DB::transaction(function () use ($operacion): void {
                DB::table('emergencias')->where('id', $operacion->emergencia_id)->lockForUpdate()->first() ?? abort(404);
                $this->incorporarConsumoInventario($operacion->emergencia_id, $operacion);
            }, 3);
            $db->table('clinico_consumos_inventario')->where('id', $operacion->id)->whereNull('sincronizado_en')->update(['sincronizado_en' => now(), 'updated_at' => now()]);
        }
        return response()->json(['recuperados' => $pendientes->count()]);
    }

    private function registrarConsumoInventario(Request $request, string $emergencia, array $datos, array $item, AccesoCompartido $acceso): JsonResponse
    {
        $usuarioId = (string) $request->user()->id;
        $itemId = (int) ($item['contableId'] ?? 0);
        abort_unless($itemId > 0, 422, 'El insumo no tiene un identificador de inventario válido.');
        $almacenId = (int) $datos['almacenId'];
        $permitidos = $acceso->almacenesPermitidos();
        abort_if($permitidos !== null && ! in_array($almacenId, array_map('intval', $permitidos), true), 403, 'No tienes acceso al almacén seleccionado.');
        $db = $acceso->conexion();
        $huella = hash('sha256', json_encode([$emergencia, $usuarioId, $almacenId, $itemId, (string) $datos['cantidad'], $datos['observacion'] ?? ''], JSON_THROW_ON_ERROR));
        $resultado = DB::transaction(function () use ($db, $request, $emergencia, $datos, $item, $itemId, $usuarioId, $almacenId, $huella): JsonResponse {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            $cuenta = $this->buscar($emergencia);
            abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada.');
            $operacion = $db->transaction(function () use ($db, $request, $emergencia, $datos, $item, $itemId, $usuarioId, $almacenId, $huella): object {
                $almacen = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
                    ->where('a.id', $almacenId)->where('a.activo', true)->where('s.activo', true)->lockForUpdate()->first(['a.id']);
                abort_unless($almacen, 422, 'El almacén no está activo.');
                $existente = $db->table('clinico_consumos_inventario')->where('id', $datos['solicitudId'])->first();
                if ($existente) {
                    abort_unless(hash_equals($existente->huella, $huella), 409, 'El registro ya existe con otros datos; conserva los valores originales al reintentar.');
                    return $existente;
                }
                $restante = (int) round((float) $datos['cantidad'] * 1000);
                $lotes = $db->table('existencias_lote as e')->join('lotes_items as l', 'l.id', '=', 'e.lote_id')
                    ->where('e.almacen_id', $almacenId)->where('l.item_id', $itemId)->where('e.cantidad', '>', 0)
                    ->where(fn ($q) => $q->whereNull('l.vencimiento')->orWhere('l.vencimiento', '>=', now()->toDateString()))
                    ->orderByRaw('CASE WHEN l.vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('l.vencimiento')->orderBy('e.id')
                    ->lockForUpdate()->get(['e.id', 'e.lote_id', 'e.cantidad']);
                $movimientos = [];
                foreach ($lotes as $lote) {
                    $cantidad = min($restante, (int) round((float) $lote->cantidad * 1000));
                    if ($cantidad <= 0) continue;
                    $db->table('existencias_lote')->where('id', $lote->id)->decrement('cantidad', $cantidad / 1000, ['updated_at' => now()]);
                    $movimientos[] = ['lote_id' => $lote->lote_id, 'cantidad' => $cantidad / 1000];
                    $restante -= $cantidad;
                    if ($restante === 0) break;
                }
                if ($restante > 0) throw ValidationException::withMessages(['cantidad' => 'No hay stock disponible y vigente suficiente en el almacén seleccionado.']);
                $fecha = now()->toIso8601String();
                $usuario = trim(($request->user()->nombres ?? '').' '.($request->user()->apellidos ?? '')) ?: 'Enfermería';
                $registro = ['id' => $datos['solicitudId'], 'tipo' => 'insumo', 'detalle' => $item['nombre'], 'cantidad' => (float) $datos['cantidad'],
                    'unidad' => $item['unidad'] ?? '', 'observacion' => $datos['observacion'] ?? '', 'fecha' => $fecha, 'usuario' => $usuario];
                $consumo = [...$item, 'itemId' => $item['id'], 'registroId' => $datos['solicitudId'], 'cantidad' => (float) $datos['cantidad'],
                    'porHora' => false, 'inicio' => $fecha, 'fin' => null, 'almacenId' => $almacenId];
                $db->table('clinico_consumos_inventario')->insert(['id' => $datos['solicitudId'], 'emergencia_id' => $emergencia,
                    'usuario_id' => $usuarioId, 'almacen_id' => $almacenId, 'item_id' => $itemId, 'cantidad' => $datos['cantidad'],
                    'huella' => $huella, 'datos_clinicos' => json_encode(['registro' => $registro, 'consumo' => $consumo], JSON_THROW_ON_ERROR),
                    'lotes' => json_encode($movimientos, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                return $db->table('clinico_consumos_inventario')->where('id', $datos['solicitudId'])->first();
            }, 3);
            return $this->incorporarConsumoInventario($emergencia, $operacion);
        }, 3);
        $db->table('clinico_consumos_inventario')->where('id', $datos['solicitudId'])->whereNull('sincronizado_en')->update(['sincronizado_en' => now(), 'updated_at' => now()]);
        return $resultado;
    }

    private function incorporarConsumoInventario(string $emergencia, object $operacion): JsonResponse
    {
        $datos = json_decode($operacion->datos_clinicos, true, 512, JSON_THROW_ON_ERROR);
        $cuenta = $this->buscar($emergencia);
        $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
        $ingreso['consumos'] ??= [];
        $ingreso['registrosEnfermeria'] ??= [];
        if (! collect($ingreso['consumos'])->contains(fn ($consumo) => ($consumo['registroId'] ?? null) === $operacion->id)) {
            $ingreso['consumos'][] = $datos['consumo'];
            $ingreso['registrosEnfermeria'][] = $datos['registro'];
            $this->sincronizarCargos($emergencia, $ingreso['consumos']);
            DB::table('emergencias')->where('id', $emergencia)->update(['datos_ingreso' => json_encode($ingreso, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
        return response()->json($this->presentar($this->buscar($emergencia)));
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
            if (! $consumo['porHora'] && collect($ingreso['consumos'])->contains(fn (array $existente): bool => ($existente['registroId'] ?? null) === $consumo['registroId'])) {
                return $ingreso;
            }
            if ($consumo['porHora']) {
                $registroIndex = collect($ingreso['consumos'])->search(fn (array $existente): bool => ($existente['registroId'] ?? null) === $consumo['registroId'] && ($existente['itemId'] ?? null) === $consumo['itemId'] && ($existente['porHora'] ?? false));
                if ($registroIndex !== false) {
                    abort_if(empty($ingreso['consumos'][$registroIndex]['fin']), 422, 'El servicio ya está en uso.');
                    $acumulado = $this->duracionConsumoMs($ingreso['consumos'][$registroIndex]);
                    foreach ($ingreso['consumos'] as $indice => $existente) {
                        if ($indice !== $registroIndex && ($existente['itemId'] ?? null) === $consumo['itemId'] && ($existente['porHora'] ?? false) && ! empty($existente['fin'])) {
                            $acumulado += $this->duracionConsumoMs($existente);
                            unset($ingreso['consumos'][$indice]);
                        }
                    }
                    $ingreso['consumos'][$registroIndex] = [...$ingreso['consumos'][$registroIndex], ...$consumo, 'tiempoAcumuladoMs' => $acumulado];
                    $ingreso['consumos'] = array_values($ingreso['consumos']);

                    return $ingreso;
                }
                $consumo['tiempoAcumuladoMs'] = 0;
            }
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
            $ordenId = null;
            $ingreso['consumos'] ??= [];
            foreach ($ingreso['consumos'] as &$consumo) {
                if (($consumo['registroId'] ?? null) === $datos['registroId'] && ($consumo['porHora'] ?? false) && empty($consumo['fin'])) {
                    $fin = now();
                    $consumo['tiempoAcumuladoMs'] = ($consumo['tiempoAcumuladoMs'] ?? 0) + max(0, $fin->getTimestamp() - Carbon::parse($consumo['inicio'])->getTimestamp()) * 1000;
                    $consumo['fin'] = $fin->toIso8601String();
                    $ordenId = $consumo['ordenId'] ?? null;
                    $encontrado = true;
                    break;
                }
            }
            unset($consumo);
            abort_unless($encontrado, 422, 'El contador ya está pausado o no existe.');
            if ($ordenId) {
                $ingreso['ordenes'] ??= [];
                foreach ($ingreso['ordenes'] as &$orden) {
                    if (($orden['id'] ?? null) === $ordenId) $orden['estado'] = 'aplicada';
                }
                unset($orden);
            }

            return $ingreso;
        });
    }

    public function signos(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'valores' => ['required', 'array', 'min:1', 'max:500'],
            'valores.*' => ['required'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'fecha' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hora' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        $cuenta = $this->buscar($emergencia);
        abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada; no se pueden registrar signos vitales.');
        $definiciones = DB::table('configuracion_signos_vitales as s')
            ->join('servicio_signo_vital as ss', 'ss.signo_id', '=', 's.id')
            ->where('ss.servicio_id', 'emergencias')
            ->where('s.activo', true)
            ->select('s.*')
            ->get();
        $permitidos = [];
        foreach ($definiciones as $signo) {
            $componentes = $signo->componentes ? json_decode($signo->componentes, true, 512, JSON_THROW_ON_ERROR) : [];
            if ($componentes) {
                foreach ($componentes as $componente) {
                    $id = $signo->id.'::'.$componente['id'];
                    $permitidos[$id] = ['nombre' => $signo->nombre.' · '.$componente['nombre'], 'unidad' => $signo->abreviatura, 'tipo' => 'Número decimal'];
                }
            } else {
                $permitidos[$signo->id] = ['nombre' => $signo->nombre, 'unidad' => $signo->abreviatura, 'tipo' => $signo->tipo];
            }
        }
        $valores = [];
        $mediciones = [];
        foreach ($datos['valores'] as $id => $valor) {
            $definicion = $permitidos[$id] ?? null;
            if (! $definicion || (! is_string($valor) && ! is_numeric($valor)) || ! trim((string) $valor)) {
                throw ValidationException::withMessages(['valores' => 'Solo se permiten signos asignados y valores válidos.']);
            }
            $valor = trim((string) $valor);
            if ($definicion['tipo'] !== 'Texto' && (! is_numeric($valor) || ! is_finite((float) $valor) || ($definicion['tipo'] === 'Número entero' && floor((float) $valor) !== (float) $valor))) {
                throw ValidationException::withMessages(['valores' => 'Una medición no corresponde al tipo de dato configurado.']);
            }
            $valores[$id] = $valor;
            $mediciones[] = ['id' => $id, ...$definicion, 'valor' => $valor];
        }

        return $this->actualizar($emergencia, function (array $ingreso) use ($datos, $valores, $mediciones): array {
            $fecha = Carbon::createFromFormat(
                'Y-m-d H:i',
                ($datos['fecha'] ?? now()->format('Y-m-d')).' '.($datos['hora'] ?? now()->format('H:i')),
            );
            $ingreso['signos'] ??= [];
            $ingreso['signos'][] = [
                'id' => (string) Str::uuid(),
                'fecha' => $fecha->toIso8601String(),
                'valores' => $valores,
                'mediciones' => $mediciones,
                'observacion' => $datos['observacion'] ?? '',
            ];
            usort($ingreso['signos'], fn (array $a, array $b): int => strcmp($a['fecha'], $b['fecha']));

            return $ingreso;
        });
    }

    public function balanceHidrico(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'id' => ['nullable', 'uuid'],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'volumen' => ['required', 'integer', 'min:1', 'max:1000000'],
            'detalle' => ['required', 'string', 'max:180'],
            'anotacion' => ['nullable', 'string', 'max:1000'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
        ]);
        DB::transaction(function () use ($request, $emergencia, $datos): void {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');
            $id = $datos['id'] ?? (string) Str::uuid();
            $existente = DB::table('emergencia_balance_hidrico')->where('id', $id)->first();
            if ($existente) {
                abort_unless($existente->emergencia_id === $emergencia, 422, 'El registro pertenece a otra atención.');

                return;
            }
            DB::table('emergencia_balance_hidrico')->insert([
                'id' => $id, 'emergencia_id' => $emergencia,
                ...$datos, 'autor_id' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json($this->presentar($this->buscar($emergencia)), 201);
    }

    public function trasladoAmbulancia(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'], 'hora' => ['required', 'date_format:H:i'],
            'destino' => ['required', 'string', 'max:200'], 'motivo' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $emergencia, $datos): void {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            abort_if($this->buscar($emergencia)->finalizada_en, 422, 'La atención ya fue finalizada.');
            $existente = DB::table('emergencia_traslados_ambulancia')->where('emergencia_id', $emergencia)->first();
            if ($existente) {
                DB::table('emergencia_traslados_ambulancia')->where('id', $existente->id)->update([...$datos, 'autor_id' => $request->user()?->id, 'updated_at' => now()]);
            } else {
                DB::table('emergencia_traslados_ambulancia')->insert([...$datos, 'id' => (string) Str::uuid(), 'emergencia_id' => $emergencia, 'autor_id' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json($this->presentar($this->buscar($emergencia)));
    }

    public function identidad(Request $request, string $emergencia): JsonResponse
    {
        $datos = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'pacienteId' => ['nullable', 'uuid', 'exists:pacientes,id'], 'cambiosPaciente' => ['nullable', 'array']]);
        $identidad = $request->except(['revision', 'pacienteId', 'cambiosPaciente']);
        foreach ($identidad as $campo => $valor) {
            if ($valor === null) {
                $identidad[$campo] = '';
                continue;
            }
            if (! is_string($valor) || mb_strlen($valor) > ($campo === 'observaciones' ? 500 : 200)) {
                return response()->json(['message' => "El campo {$campo} no es válido."], 422);
            }
        }

        return DB::transaction(function () use ($emergencia, $identidad, $datos): JsonResponse {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            $cuenta = $this->buscar($emergencia);
            abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada.');
            $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
            $ingreso['identidad'] = array_filter(array_merge($ingreso['identidad'] ?? [], $identidad), fn ($valor) => $valor !== '');
            $documento = $this->normalizarDocumento($ingreso['identidad']['documento'] ?? '');
            if ($documento !== '' && $this->pacienteOcupado($documento, $cuenta->id)) {
                throw ValidationException::withMessages(['documento' => 'Este CI ya tiene una atención de emergencia activa. No se puede registrar al mismo paciente dos veces.']);
            }
            $pacienteId = $datos['pacienteId'] ?? $this->pacientePorCi($documento);
            if (! empty($datos['cambiosPaciente'])) {
                abort_unless(! empty($datos['pacienteId']), 422, 'Selecciona el paciente cuya ficha deseas actualizar.');
                $cambios = Validator::make($datos['cambiosPaciente'], [
                    'nombres' => ['sometimes', 'required', 'string', 'max:100'],
                    'apellido_paterno' => ['sometimes', 'required', 'string', 'max:100'],
                    'apellido_materno' => ['sometimes', 'required', 'string', 'max:100'],
                    'ci' => ['sometimes', 'required', 'string', 'min:3', 'max:25', Rule::unique('pacientes', 'ci')->ignore($pacienteId)],
                    'fecha_nacimiento' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
                    'genero' => ['sometimes', 'required', Rule::in(['Femenino', 'Masculino', 'Otro'])],
                    'complemento_ci' => ['nullable', 'string', 'max:10'], 'expedido_en' => ['nullable', 'string', 'max:50'],
                    'nit' => ['nullable', 'string', 'max:25'], 'razon_social' => ['nullable', 'string', 'max:180'],
                    'telefono' => ['nullable', 'string', 'max:30'], 'ci_con_qr' => ['sometimes', 'boolean'],
                    'pais' => ['sometimes', 'required', 'string', 'max:80'], 'departamento' => ['sometimes', 'required', 'string', 'max:80'],
                    'ciudad' => ['nullable', 'string', 'max:100'], 'zona_barrio' => ['nullable', 'string', 'max:150'],
                    'direccion_domicilio' => ['nullable', 'string', 'max:255'],
                ])->validate();
                Paciente::query()->whereKey($pacienteId)->firstOrFail()->update($cambios);
            }
            if (! empty($datos['pacienteId'])) {
                $ciPaciente = Paciente::query()->whereKey($pacienteId)->value('ci');
                if ($documento === '' || $this->normalizarDocumento($ciPaciente) !== $documento) {
                    throw ValidationException::withMessages(['documento' => 'El documento debe coincidir con la ficha del paciente seleccionado.']);
                }
            }
            DB::table('emergencias')->where('id', $cuenta->id)->update([
                'datos_ingreso' => json_encode($ingreso), 'paciente_activo_ci' => $documento ?: null, 'updated_at' => now(),
            ]);
            DB::table('atenciones')->where('id', $cuenta->atencion_id)->update([
                'paciente_id' => $pacienteId, 'updated_at' => now(),
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

        return DB::transaction(function () use ($emergencia, $datos): JsonResponse {
            DB::table('emergencias')->where('id', $emergencia)->lockForUpdate()->first() ?? abort(404);
            $cuenta = $this->buscar($emergencia);
            abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada.');
            $ingreso = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
            $fechaCierre = now()->toIso8601String();
            $ingreso['consumos'] ??= [];
            foreach ($ingreso['consumos'] as &$consumo) {
                if (($consumo['porHora'] ?? false) && empty($consumo['fin'])) {
                    $consumo['tiempoAcumuladoMs'] = $this->duracionConsumoMs($consumo) + max(0, now()->getTimestamp() - Carbon::parse($consumo['inicio'])->getTimestamp()) * 1000;
                    $consumo['fin'] = $fechaCierre;
                }
            }
            unset($consumo);
            $ingreso['cierre'] = [...$datos, 'fecha' => $fechaCierre, 'usuario' => 'Profesional de turno'];
            $this->sincronizarCargos($cuenta->id, $ingreso['consumos'] ?? []);
            DB::table('emergencias')->where('id', $cuenta->id)->update(['datos_ingreso' => json_encode($ingreso), 'updated_at' => now()]);
            DB::table('emergencias')->where('id', $cuenta->id)->update(['ubicacion_activa' => null, 'paciente_activo_ci' => null]);
            DB::table('atenciones')->where('id', $cuenta->atencion_id)->update(['estado' => 'finalizada', 'finalizada_en' => now(), 'updated_at' => now()]);

            return response()->json($this->presentar($this->buscar($emergencia)));
        });
    }

    /** @param array<string, mixed> $consumo */
    private function duracionConsumoMs(array $consumo): int
    {
        if (isset($consumo['tiempoAcumuladoMs'])) {
            return max(0, (int) $consumo['tiempoAcumuladoMs']);
        }
        if (empty($consumo['fin'])) {
            return 0;
        }

        return max(0, Carbon::parse($consumo['fin'])->getTimestamp() - Carbon::parse($consumo['inicio'])->getTimestamp()) * 1000;
    }

    private function actualizar(string $id, callable $mutar): JsonResponse
    {
        return DB::transaction(function () use ($id, $mutar): JsonResponse {
            DB::table('emergencias')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $cuenta = $this->buscar($id);
            abort_if($cuenta->finalizada_en, 422, 'La atención ya fue finalizada.');
            $anterior = json_decode($cuenta->datos_ingreso ?? '[]', true) ?: [];
            $ingreso = $mutar($anterior);
            if (($ingreso['consumos'] ?? []) !== ($anterior['consumos'] ?? [])) {
                $this->sincronizarCargos($id, $ingreso['consumos'] ?? []);
            }
            DB::table('emergencias')->where('id', $id)->update(['datos_ingreso' => json_encode($ingreso), 'updated_at' => now()]);

            return response()->json($this->presentar($this->buscar($id)));
        });
    }

    private function sincronizarCargos(string $emergenciaId, array $consumos): void
    {
        $cuentaId = DB::table('cuentas_atencion_emergencia')->where('emergencia_id', $emergenciaId)->value('id');
        abort_unless($cuentaId, 500, 'La atención no tiene una cuenta asociada.');
        $ids = [];
        foreach ($consumos as $consumo) {
            $registroId = $consumo['registroId'] ?? null;
            $itemId = $consumo['itemId'] ?? $consumo['id'] ?? null;
            if (! $registroId || ! $itemId) continue;
            $ids[] = $registroId;
            $query = DB::table('cargos_atencion_emergencia')->where('cuenta_id', $cuentaId)->where('registro_id', $registroId);
            $datos = ['item_id' => $itemId, 'datos' => json_encode($consumo), 'vigente' => true, 'updated_at' => now()];
            if ($query->exists()) {
                $query->update($datos);
            } else {
                DB::table('cargos_atencion_emergencia')->insert([...$datos, 'id' => (string) Str::uuid(), 'cuenta_id' => $cuentaId, 'registro_id' => $registroId, 'created_at' => now()]);
            }
        }
        DB::table('cargos_atencion_emergencia')->where('cuenta_id', $cuentaId)->when($ids, fn ($query) => $query->whereNotIn('registro_id', $ids))->update(['vigente' => false, 'updated_at' => now()]);
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
        $signos = $ingreso['signos'] ?? [];
        foreach ($signos as &$signo) {
            if (isset($signo['valores']['saturacion']) && ! isset($signo['valores']['saturacionAmbiente'])) {
                $signo['valores']['saturacionAmbiente'] = $signo['valores']['saturacion'];
            }
        }
        unset($signo);

        return [
            'id' => $emergencia->id,
            'pacienteId' => $emergencia->paciente_id ?? null,
            'cubiculo' => $ingreso['cubiculo'] ?? '',
            'inicio' => $emergencia->iniciada_en,
            'fin' => $emergencia->finalizada_en,
            'revision' => crc32($emergencia->datos_ingreso ?? ''),
            'identidad' => $identidad,
            'signos' => $signos,
            'consumos' => $ingreso['consumos'] ?? [],
            'evaluaciones' => $ingreso['evaluaciones'] ?? [],
            'ordenes' => $ingreso['ordenes'] ?? [],
            'registrosEnfermeria' => $ingreso['registrosEnfermeria'] ?? [],
            'balanceHidrico' => DB::table('emergencia_balance_hidrico')->where('emergencia_id', $emergencia->id)->orderBy('fecha')->orderBy('hora')->orderBy('created_at')->get()->map(fn ($registro) => [
                'id' => $registro->id, 'cuentaId' => $registro->emergencia_id, 'tipo' => $registro->tipo,
                'volumen' => (int) $registro->volumen, 'detalle' => $registro->detalle, 'anotacion' => $registro->anotacion,
                'fecha' => $registro->fecha, 'hora' => $registro->hora,
            ]),
            'trasladoAmbulancia' => DB::table('emergencia_traslados_ambulancia')->where('emergencia_id', $emergencia->id)->first(['fecha', 'hora', 'motivo', 'destino']),
            'cierre' => $ingreso['cierre'] ?? null,
            'cuenta' => DB::table('cuentas_atencion_emergencia')->where('emergencia_id', $emergencia->id)->first(['id', 'estado', 'moneda']),
        ];
    }
}
