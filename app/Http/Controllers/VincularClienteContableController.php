<?php

namespace App\Http\Controllers;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Pacientes\Models\Paciente;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class VincularClienteContableController extends Controller
{
    public function __invoke(Request $request, Paciente $paciente): JsonResponse
    {
        app(AccesoCompartido::class)->exigir($request->user()->id, 'VENTAS_REGISTRAR');
        $url = config('services.contable.url');
        $token = config('services.contable.token');
        abort_unless($url && $token, 503, 'La conexión con el contable no está configurada.');
        try {
            $respuesta = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(10)
                ->post(rtrim($url, '/').'/api/integraciones/clinico/clientes', $paciente->only(['id', 'ci', 'nombres', 'apellido_paterno', 'apellido_materno', 'telefono']));
        } catch (ConnectionException) {
            abort(503, 'No se pudo conectar con el contable. Intenta nuevamente.');
        }
        if ($respuesta->status() === 422) {
            return response()->json($respuesta->json(), 422);
        }
        abort_unless($respuesta->successful(), 503, 'No se pudo vincular el paciente con el contable.');

        return response()->json($respuesta->json(), 201);
    }
}
