<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfiguracionSignosVitalesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('configuracion_signos_vitales')->where('activo', true)->orderBy('created_at')->orderBy('id')->get()->map(fn ($signo) => $this->presentar($signo))]);
    }

    public function store(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'SIGNOS_VITALES_REGISTRAR');
        $datos = $this->validar($request);
        $id = 'signo-'.Str::uuid();
        DB::table('configuracion_signos_vitales')->insert($datos + ['id' => $id, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->presentar(DB::table('configuracion_signos_vitales')->where('id', $id)->first())], 201);
    }

    public function update(Request $request, string $signo, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'SIGNOS_VITALES_REGISTRAR');
        $consulta = DB::table('configuracion_signos_vitales')->where('id', $signo)->where('activo', true);
        abort_unless($consulta->exists(), 404);
        $consulta->update($this->validar($request, $signo) + ['updated_at' => now()]);

        return response()->json(['data' => $this->presentar($consulta->first())]);
    }

    public function destroy(Request $request, string $signo, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'SIGNOS_VITALES_REGISTRAR');
        $actualizados = DB::table('configuracion_signos_vitales')->where('id', $signo)->where('activo', true)->update(['activo' => false, 'updated_at' => now()]);
        abort_unless($actualizados, 404);

        return response()->json(['message' => 'Signo vital eliminado.']);
    }

    private function validar(Request $request, ?string $id = null): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'unidad' => ['required', 'string', 'max:120'],
            'abreviatura' => ['required', 'string', 'max:32'],
            'tipo' => ['required', Rule::in(['Número entero', 'Número decimal', 'Texto'])],
            'rangoReferencia' => ['nullable', 'array:nombre,minimo,maximo,fuente,contexto'],
            'rangoReferencia.nombre' => ['required_with:rangoReferencia', 'string', 'max:120'],
            'rangoReferencia.minimo' => ['required_with:rangoReferencia', 'numeric'],
            'rangoReferencia.maximo' => ['required_with:rangoReferencia', 'numeric', 'gt:rangoReferencia.minimo'],
            'rangoReferencia.fuente' => ['nullable', 'string', 'max:500'],
            'rangoReferencia.contexto' => ['nullable', 'string', 'max:1000'],
            'componentes' => ['nullable', 'array', 'max:20'],
            'componentes.*' => ['array:id,nombre,rangoReferencia'],
            'componentes.*.id' => ['required', 'string', 'max:80', 'distinct'],
            'componentes.*.nombre' => ['required', 'string', 'max:120', 'distinct'],
            'componentes.*.rangoReferencia' => ['nullable', 'array:nombre,minimo,maximo'],
            'componentes.*.rangoReferencia.nombre' => ['required_with:componentes.*.rangoReferencia', 'string', 'max:120'],
            'componentes.*.rangoReferencia.minimo' => ['required_with:componentes.*.rangoReferencia', 'numeric'],
            'componentes.*.rangoReferencia.maximo' => ['required_with:componentes.*.rangoReferencia', 'numeric'],
        ]);
        $duplicado = DB::table('configuracion_signos_vitales')->where('activo', true)->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($datos['nombre']))])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($duplicado) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un signo vital con ese nombre.']);
        }
        $rango = $datos['rangoReferencia'] ?? null;
        foreach ($datos['componentes'] ?? [] as $componente) {
            $referencia = $componente['rangoReferencia'] ?? null;
            if ($referencia && $referencia['minimo'] >= $referencia['maximo']) {
                throw ValidationException::withMessages(['componentes' => 'El límite mínimo de cada dato debe ser menor que el máximo.']);
            }
        }
        if ($rango && ($datos['tipo'] === 'Texto' || ($datos['tipo'] === 'Número entero' && (floor((float) $rango['minimo']) != $rango['minimo'] || floor((float) $rango['maximo']) != $rango['maximo'])))) {
            throw ValidationException::withMessages(['rangoReferencia' => 'El rango no corresponde al tipo de dato.']);
        }

        return ['nombre' => trim($datos['nombre']), 'unidad' => trim($datos['unidad']), 'abreviatura' => trim($datos['abreviatura']), 'tipo' => $datos['tipo'], 'rango_referencia' => $rango ? json_encode($rango, JSON_THROW_ON_ERROR) : null, 'componentes' => ! empty($datos['componentes']) ? json_encode($datos['componentes'], JSON_THROW_ON_ERROR) : null];
    }

    private function presentar(object $signo): array
    {
        return ['id' => $signo->id, 'nombre' => $signo->nombre, 'unidad' => $signo->unidad, 'abreviatura' => $signo->abreviatura, 'tipo' => $signo->tipo, 'rangoReferencia' => $signo->rango_referencia ? json_decode($signo->rango_referencia, true, 512, JSON_THROW_ON_ERROR) : null, 'componentes' => $signo->componentes ? json_decode($signo->componentes, true, 512, JSON_THROW_ON_ERROR) : []];
    }
}
