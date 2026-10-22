<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Farmacia\CatalogoCompartido;
use Illuminate\Http\JsonResponse;

class FarmaciaAlmacenController
{
    public function index(CatalogoCompartido $catalogo): JsonResponse
    {
        return response()->json(['data' => array_values(array_filter($catalogo->obtener(), fn (array $item): bool => $item['tipo'] !== 'servicio'))]);
    }

    public function store(): JsonResponse
    {
        return response()->json(['message' => 'Registra productos, lotes e ingresos en el sistema contable. El inventario de farmacia es compartido.'], 409);
    }
}
