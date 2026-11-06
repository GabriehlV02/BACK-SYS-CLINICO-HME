<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UnidadesMedidaSignosVitalesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('unidades_medida_signos_vitales')->select('id', 'nombre', 'abreviatura')->orderBy('nombre')->get()]);
    }

    public function store(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $acceso->exigir($request->user()->id, 'SIGNOS_VITALES_REGISTRAR');
        $request->merge([
            'nombre' => is_string($request->input('nombre')) ? Str::squish($request->input('nombre')) : $request->input('nombre'),
            'abreviatura' => is_string($request->input('abreviatura')) ? trim($request->input('abreviatura')) : $request->input('abreviatura'),
        ]);
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:120'], 'abreviatura' => ['required', 'string', 'max:32']]);
        $clave = Str::lower(Str::ascii($datos['nombre']));
        validator(['clave' => $clave], ['clave' => ['required', Rule::unique('unidades_medida_signos_vitales', 'clave')]], ['clave.unique' => 'Ya existe una unidad con ese nombre.'])->validate();
        $id = DB::table('unidades_medida_signos_vitales')->insertGetId($datos + ['clave' => $clave, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id] + $datos], 201);
    }
}
