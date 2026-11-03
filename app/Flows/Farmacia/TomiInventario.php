<?php

namespace App\Flows\Farmacia;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Support\Facades\DB;

class TomiInventario
{
    private const PALABRAS_IGNORADAS = ['cuanto', 'cuantos', 'cuantas', 'hay', 'tengo', 'tenemos', 'stock', 'disponible', 'disponibilidad', 'producto', 'productos', 'medicamento', 'medicamentos', 'buscar', 'buscame', 'busca', 'encuentra', 'encontrar', 'item', 'items', 'vender', 'venta', 'vendere', 'vendamos', 'vencimiento', 'vence', 'vencen', 'ubicacion', 'donde', 'esta', 'estan', 'sucursal', 'almacen', 'estante', 'por', 'favor', 'debo', 'necesito', 'quiero', 'para', 'el', 'la', 'los', 'las', 'un', 'una', 'en', 'del', 'al', 'me', 'mi', 'primero', 'primera', 'mejor', 'decir', 'indicar', 'recomienda', 'recomendacion', 'prioriza', 'priorizar', 'proximo', 'proxima', 'proximos', 'proximas', 'fecha', 'lote', 'lotes', 'unidades', 'unidad', 'cajas'];

    public function responder(string $consulta): string
    {
        $consulta = trim($consulta);
        $intenciones = $this->normalizar($consulta);
        if (preg_match('/\b(hola|buenas|buenos|buenas tardes|buenas noches|quien eres|como te llamas)\b/u', $intenciones)) {
            return '¡Hola! Soy TOMI, tu asistente de inventario de farmacia. Puedo ayudarte a revisar existencias, sucursales, ubicaciones y vencimientos. ¿Qué producto buscas?';
        }
        preg_match('/\b(\d{1,6})\s*(?:unidades?|uds?|piezas?)?\b/u', $consulta, $coincidenciaCantidad);
        $solicitado = isset($coincidenciaCantidad[1]) ? (float) $coincidenciaCantidad[1] : null;
        $consultaNormalizada = $this->normalizar($consulta);
        $terminos = array_values(array_unique(array_filter(preg_split('/\s+/', $consultaNormalizada) ?: [], fn (string $termino): bool => mb_strlen($termino) > 1 && ! in_array($termino, self::PALABRAS_IGNORADAS, true) && ! ctype_digit($termino))));

        if ($terminos === []) {
            return 'Soy TOMI y respondo consultas de inventario de farmacia: productos, cantidades, sucursales, almacenes, estantes y vencimientos. ¿Qué producto necesitas revisar?';
        }

        $acceso = app(AccesoCompartido::class);
        $almacenes = $acceso->almacenesPermitidos();
        if ($almacenes === []) {
            return 'Tu usuario no tiene almacenes de inventario asignados para consultar.';
        }

        $db = DB::connection('usuarios');
        if (! $db->getSchemaBuilder()->hasTable('item_almacen_ubicaciones')) {
            return 'El inventario está conectado, pero falta habilitar el registro de ubicaciones por estante. Consulta con administración del sistema.';
        }

        $consultaSucursal = collect($terminos)->first(fn (string $termino): bool => $db->table('sucursales_inventario')->whereLike('nombre', '%'.$termino.'%')->exists());
        $consultaAlmacen = collect($terminos)->first(fn (string $termino): bool => $db->table('almacenes_inventario')->whereLike('nombre', '%'.$termino.'%')->exists());
        $terminosProducto = array_values(array_diff($terminos, array_filter([$consultaSucursal, $consultaAlmacen])));

        $query = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')
            ->join('lotes_items as l', 'l.item_id', '=', 'i.id')
            ->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
            ->join('almacenes_inventario as a', 'a.id', '=', 'e.almacen_id')
            ->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->leftJoin('item_almacen_ubicaciones as u', function ($join): void {
                $join->on('u.item_id', '=', 'i.id')->on('u.almacen_id', '=', 'a.id');
            })
            ->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
            ->where('a.activo', true)->where('s.activo', true)
            ->when($almacenes !== null, fn ($builder) => $builder->whereIn('e.almacen_id', $almacenes));

        if ($terminosProducto !== []) {
            $query->where(function ($builder) use ($terminosProducto): void {
                foreach ($terminosProducto as $indice => $termino) {
                    $metodo = $indice === 0 ? 'where' : 'orWhere';
                    $builder->{$metodo}(function ($campos) use ($termino): void {
                        $campos->whereLike('i.nombre_comercial', '%'.$termino.'%')->orWhereLike('i.marca', '%'.$termino.'%')
                            ->orWhereLike('i.presentacion', '%'.$termino.'%')->orWhereLike('g.nombre', '%'.$termino.'%');
                    });
                }
            });
        }
        if ($consultaSucursal !== null) {
            $query->whereLike('s.nombre', '%'.$consultaSucursal.'%');
        }
        if ($consultaAlmacen !== null) {
            $query->whereLike('a.nombre', '%'.$consultaAlmacen.'%');
        }

        $lotes = $query->get([
            'i.id as item_id', 'i.producto_base_id', 'i.nombre_comercial', 'i.marca', 'i.presentacion', 'g.nombre as generico',
            'l.numero as lote', 'l.vencimiento', 'e.cantidad', 'a.id as almacen_id', 'a.nombre as almacen', 's.nombre as sucursal', 'u.ubicacion',
        ]);

        if ($lotes->isEmpty()) {
            return $this->respuestaSinCoincidencias($db, $terminosProducto, $consulta);
        }

        $vencidas = $lotes->filter(fn (object $lote): bool => (float) $lote->cantidad > 0 && $lote->vencimiento && $lote->vencimiento < now()->toDateString());
        $vigentes = $lotes->filter(fn (object $lote): bool => ! $lote->vencimiento || $lote->vencimiento >= now()->toDateString())
            ->filter(fn (object $lote): bool => (float) $lote->cantidad > 0)
            ->sortBy(fn (object $lote): string => $lote->vencimiento ?: '9999-12-31');
        $productos = $vigentes->groupBy(fn (object $lote): string => $lote->producto_base_id.'|'.$lote->presentacion);
        $respuesta = [];

        foreach ($productos as $producto) {
            $ejemplo = $producto->first();
            $titulo = trim($ejemplo->generico.' '.$ejemplo->presentacion.' ('.$ejemplo->marca.')');
            $total = (float) $producto->sum('cantidad');
            $respuesta[] = $titulo.' — stock vigente: '.$this->formatearCantidad($total).'.';
            $porAlmacen = $producto->groupBy('almacen_id');
            foreach ($porAlmacen as $existenciasAlmacen) {
                $ubicacion = $existenciasAlmacen->first()->ubicacion ?: 'ubicación sin registrar';
                $respuesta[] = '• '.$existenciasAlmacen->first()->sucursal.' / '.$existenciasAlmacen->first()->almacen.' — '.$ubicacion.' — '.$this->formatearCantidad((float) $existenciasAlmacen->sum('cantidad')).' unidades.';
            }

            if ($solicitado !== null) {
                $restante = $solicitado;
                foreach ($producto as $lote) {
                    if ($restante <= 0) {
                        break;
                    }
                    $asignado = min($restante, (float) $lote->cantidad);
                    $vence = $lote->vencimiento ?: 'sin fecha de vencimiento registrada';
                    $ubicacion = $lote->ubicacion ?: 'ubicación sin registrar';
                    $respuesta[] = 'Para despachar primero: '.$this->formatearCantidad($asignado).' de '.$lote->marca.' en '.$lote->sucursal.' / '.$lote->almacen.' ('.$ubicacion.'), lote '.$lote->lote.', vence '.$vence.'.';
                    $restante -= $asignado;
                }
                if ($restante > 0) {
                    $respuesta[] = 'Faltarían '.$this->formatearCantidad($restante).' unidades para completar las '.$this->formatearCantidad($solicitado).' solicitadas.';
                }
            }
        }

        if ($vencidas->isNotEmpty()) {
            $respuesta[] = 'Hay '.$vencidas->count().' registro(s) con vencimiento pasado; no los incluyo en la recomendación de venta.';
        }

        if ($productos->count() > 1) {
            $respuesta[] = 'Encontré distintas presentaciones o productos base. No mezclé sus cantidades; confirma cuál presentación necesitas.';
        }

        return implode("\n", $respuesta);
    }

    private function respuestaSinCoincidencias($db, array $terminosProducto, string $consulta): string
    {
        $coincidencias = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')
            ->when($terminosProducto !== [], function ($builder) use ($terminosProducto): void {
                $builder->where(function ($nombres) use ($terminosProducto): void {
                    foreach ($terminosProducto as $termino) {
                        $nombres->orWhereLike('i.nombre_comercial', '%'.$termino.'%')->orWhereLike('i.marca', '%'.$termino.'%')->orWhereLike('g.nombre', '%'.$termino.'%');
                    }
                });
            })
            ->limit(5)->get(['i.nombre_comercial', 'i.marca', 'i.presentacion', 'g.nombre as generico']);

        if ($coincidencias->isNotEmpty()) {
            $lista = $coincidencias->map(fn (object $item): string => trim($item->generico.' '.$item->presentacion.' ('.$item->marca.')'))->unique()->implode('; ');

            return 'Encontré estos productos en el catálogo, pero no tienen existencias registradas en los almacenes a los que tienes acceso: '.$lista.'.';
        }

        return 'No encontré «'.$consulta.'» en el inventario visible. Prueba con el nombre genérico o la marca; también puedo buscar por una palabra clave.';
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto));
    }

    private function formatearCantidad(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 3, '.', ''), '0'), '.');
    }
}
