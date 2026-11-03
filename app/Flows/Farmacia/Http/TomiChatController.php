<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use App\Flows\Farmacia\FarmaciaInventarioConversacion;
use App\Flows\Farmacia\TomiInventario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TomiChatController
{
    public function estado()
    {
        return response()->json([
            'configured' => true,
            'model' => 'inventario-en-vivo',
        ]);
    }

    public function __invoke(Request $request)
    {
        $datos = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:16'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:4000'],
            'model' => ['nullable', Rule::in(['gpt-6-luna', 'gpt-6.1-terra'])],
            'style' => ['nullable', 'string', 'max:1200'],
        ]);

        $messages = collect($datos['messages'])
            ->map(fn (array $mensaje): array => [
                'role' => $mensaje['role'],
                'content' => $mensaje['content'],
            ])
            ->values()
            ->all();

        if (($messages[array_key_last($messages)]['role'] ?? null) !== 'user') {
            return response()->json(['message' => 'El último mensaje debe ser una consulta del usuario.'], 422);
        }

        $consulta = $messages[array_key_last($messages)]['content'];
        $flujoInventario = app(FarmaciaInventarioConversacion::class);
        $inventario = $flujoInventario->responder($consulta, $request->user(), app(AccesoCompartido::class));
        if ($inventario !== null) {
            return response()->json(['message' => $inventario, 'inventarioFinalizadoId' => $flujoInventario->inventarioFinalizadoId()]);
        }

        return response()->json(['message' => app(TomiInventario::class)->responder($consulta)]);
    }
}
