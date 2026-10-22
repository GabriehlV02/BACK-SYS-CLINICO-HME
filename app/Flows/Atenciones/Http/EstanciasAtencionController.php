<?php

namespace App\Flows\Atenciones\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EstanciasAtencionController
{
    private const AREAS = ['EMERGENCIA', 'CONSULTORIO', 'INTERNACION', 'QUIROFANO', 'RECUPERACION', 'TERAPIA_INTENSIVA'];

    public function index(string $atencion): JsonResponse
    {
        abort_unless(DB::table('atenciones')->where('id', $atencion)->exists(), 404);

        $estancias = DB::table('estancias_atencion')
            ->where('atencion_id', $atencion)
            ->orderBy('ingresada_en')
            ->get()
            ->map(fn (object $estancia) => $this->conProfesionales($estancia));

        return response()->json(['data' => $estancias]);
    }

    public function ingresar(Request $request, string $atencion): JsonResponse
    {
        abort_unless(DB::table('atenciones')->where('id', $atencion)->exists(), 404);
        $datos = $request->validate([
            'area' => ['required', Rule::in(self::AREAS)],
            'espacio_atencion_id' => ['nullable', 'uuid', Rule::exists('espacios_atencion', 'id')],
            'motivo_ingreso' => ['nullable', 'string', 'max:255'],
            'datos_area' => ['nullable', 'array'],
            'profesionales' => ['array'],
            'profesionales.*.usuario_id' => ['required', 'uuid', Rule::exists('usuarios.usuarios_sistema', 'id')->where('tipo_usuario', 'EMPRESA')->where('estado', 'ACTIVO')],
            'profesionales.*.medico_id' => ['prohibited'],
            'profesionales.*.rol' => ['required_with:profesionales', 'string', 'max:40'],
        ]);

        $estanciaId = (string) Str::uuid();
        DB::transaction(function () use ($atencion, $datos, $estanciaId): void {
            DB::table('atenciones')->where('id', $atencion)->lockForUpdate()->first();
            // Solo una ubicación activa por atención: el traslado debe cerrar la anterior primero.
            abort_if(DB::table('estancias_atencion')->where('atencion_id', $atencion)->where('estado', 'ACTIVA')->exists(), 422, 'La atención ya tiene una estancia activa; use el traslado.');
            DB::table('estancias_atencion')->insert([
                'id' => $estanciaId, 'atencion_id' => $atencion, 'area' => $datos['area'],
                'espacio_atencion_id' => $datos['espacio_atencion_id'] ?? null, 'estado' => 'ACTIVA',
                'motivo_ingreso' => $datos['motivo_ingreso'] ?? null,
                'datos_area' => isset($datos['datos_area']) ? json_encode($datos['datos_area']) : null,
                'ingresada_en' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->asignarProfesionales($estanciaId, $datos['profesionales'] ?? []);
        });

        return response()->json(['data' => $this->conProfesionales(DB::table('estancias_atencion')->find($estanciaId))], 201);
    }

    public function trasladar(Request $request, string $atencion, string $estancia): JsonResponse
    {
        $origen = DB::table('estancias_atencion')->where('id', $estancia)->where('atencion_id', $atencion)->where('estado', 'ACTIVA')->first();
        abort_unless($origen, 404);
        $datos = $request->validate([
            'area' => ['required', Rule::in(self::AREAS)], 'espacio_atencion_id' => ['nullable', 'uuid', Rule::exists('espacios_atencion', 'id')],
            'motivo_salida' => ['nullable', 'string', 'max:255'], 'motivo_ingreso' => ['nullable', 'string', 'max:255'],
            'datos_area' => ['nullable', 'array'], 'profesionales' => ['array'],
            'profesionales.*.usuario_id' => ['required', 'uuid', Rule::exists('usuarios.usuarios_sistema', 'id')->where('tipo_usuario', 'EMPRESA')->where('estado', 'ACTIVO')],
            'profesionales.*.medico_id' => ['prohibited'],
            'profesionales.*.rol' => ['required_with:profesionales', 'string', 'max:40'],
        ]);
        $destinoId = (string) Str::uuid();
        DB::transaction(function () use ($origen, $datos, $destinoId): void {
            DB::table('atenciones')->where('id', $origen->atencion_id)->lockForUpdate()->first();
            abort_unless(DB::table('estancias_atencion')->where('id', $origen->id)->where('estado', 'ACTIVA')->exists(), 422, 'La estancia ya fue trasladada.');
            DB::table('estancias_atencion')->where('id', $origen->id)->update(['estado' => 'FINALIZADA', 'motivo_salida' => $datos['motivo_salida'] ?? null, 'egresada_en' => now(), 'updated_at' => now()]);
            DB::table('estancias_atencion')->insert(['id' => $destinoId, 'atencion_id' => $origen->atencion_id, 'area' => $datos['area'], 'espacio_atencion_id' => $datos['espacio_atencion_id'] ?? null, 'estado' => 'ACTIVA', 'motivo_ingreso' => $datos['motivo_ingreso'] ?? null, 'datos_area' => isset($datos['datos_area']) ? json_encode($datos['datos_area']) : null, 'ingresada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->asignarProfesionales($destinoId, $datos['profesionales'] ?? []);
        });

        return response()->json(['data' => $this->conProfesionales(DB::table('estancias_atencion')->find($destinoId))], 201);
    }

    private function asignarProfesionales(string $estanciaId, array $profesionales): void
    {
        foreach ($profesionales as $profesional) {
            DB::table('estancia_profesionales')->insert(['id' => (string) Str::uuid(), 'estancia_atencion_id' => $estanciaId, 'usuario_id' => $profesional['usuario_id'], 'rol' => $profesional['rol'], 'asignado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function conProfesionales(object $estancia): object
    {
        $estancia->datos_area = json_decode($estancia->datos_area ?? 'null', true);
        $estancia->profesionales = DB::table('estancia_profesionales')->where('estancia_atencion_id', $estancia->id)->whereNull('retirado_en')->get();

        return $estancia;
    }
}
