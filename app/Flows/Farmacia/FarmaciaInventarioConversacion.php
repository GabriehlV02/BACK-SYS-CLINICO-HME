<?php

namespace App\Flows\Farmacia;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FarmaciaInventarioConversacion
{
    private ?int $inventarioFinalizadoId = null;

    public function inventarioFinalizadoId(): ?int
    {
        return $this->inventarioFinalizadoId;
    }

    public function responder(string $mensaje, object $usuario, AccesoCompartido $acceso): ?string
    {
        $db = $acceso->conexion();
        $normalizado = $this->normalizar($mensaje);

        if (preg_match('/\bsalir (?:del )?modo inventario\b/u', $normalizado)) {
            $sesion = $this->sesionActiva($db, $usuario->id);
            if (! $sesion) {
                return 'No tienes un inventario en curso para cerrar.';
            }
            $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
            Cache::forget($this->claveConteoPendiente($usuario->id, $sesion->id));
            $db->table('farmacia_inventarios')->where('id', $sesion->id)->update(['estado' => 'CANCELADO', 'updated_at' => now()]);

            return 'Salí del modo inventario. El registro quedó cancelado y conservado en el historial.';
        }

        if (preg_match('/\b(?:finaliza|finalizar|termina|terminar|cierra|cerrar) (?:el )?modo inventario\b/u', $normalizado)
            || preg_match('/\b(?:finaliza|finalizar|termina|terminar|cierra|cerrar) (?:el )?inventario\b/u', $normalizado)) {
            $sesion = $this->sesionActiva($db, $usuario->id);
            if (! $sesion) {
                return 'No tienes un inventario activo para finalizar.';
            }
            $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
            Cache::forget($this->claveConteoPendiente($usuario->id, $sesion->id));

            return $this->finalizar($sesion, $db);
        }

        if (preg_match('/\bmodo inventario\b/u', $normalizado)) {
            return $this->iniciar($mensaje, $normalizado, $usuario, $acceso);
        }

        $sesion = $this->sesionActiva($db, $usuario->id);
        if (! $sesion) {
            return null;
        }

        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        if ($sesion->estado === 'ESPERANDO_ALMACEN') {
            return $this->elegirAlmacen($sesion, $normalizado, $acceso);
        }

        if (preg_match('/\b(?:siguiente|proximo|proxima)\b/u', $normalizado)) {
            $clavePendiente = $this->claveConteoPendiente($usuario->id, $sesion->id);
            $anterior = Cache::pull($clavePendiente);
            $prefijo = $anterior ? 'Dejé sin guardar el conteo pendiente de '.$anterior['codigo'].'. ' : '';

            return $prefijo.$this->siguientePendiente($sesion, $db);
        }

        return $this->registrarConteo($sesion, $mensaje, $db, $usuario->id);
    }

    private function iniciar(string $mensaje, string $normalizado, object $usuario, AccesoCompartido $acceso): string
    {
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $db = $acceso->conexion();
        $actual = $this->sesionActiva($db, $usuario->id);
        if ($actual) {
            return $actual->estado === 'ESPERANDO_ALMACEN'
                ? $this->elegirAlmacen($actual, $normalizado, $acceso)
                : 'Ya hay un inventario en curso para '.$actual->almacen.'. Dicta el código del producto y la cantidad contada, por ejemplo: FAR002123, 10 unidades.';
        }

        $id = $db->table('farmacia_inventarios')->insertGetId([
            'sucursal_id' => null, 'almacen_id' => null, 'estado' => 'ESPERANDO_ALMACEN', 'iniciado_en' => now(),
            'iniciado_por' => $usuario->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sesion = (object) ['id' => $id];

        return $this->elegirAlmacen($sesion, $normalizado, $acceso, true, $mensaje);
    }

    private function elegirAlmacen(object $sesion, string $normalizado, AccesoCompartido $acceso, bool $nuevo = false, string $mensaje = ''): string
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.activo', true)->where('s.activo', true)->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))
            ->orderBy('s.nombre')->orderBy('a.nombre')->get(['a.id', 'a.sucursal_id', 'a.nombre as almacen', 's.nombre as sucursal']);
        if ($almacenes->isEmpty()) {
            return 'No veo almacenes activos asignados a tu usuario. Pide a administración que registre la sucursal y te dé acceso al almacén.';
        }

        $coincidenciasAlmacen = $almacenes->filter(fn (object $almacen): bool => str_contains($normalizado, $this->normalizar($almacen->almacen)));
        if ($coincidenciasAlmacen->count() === 1) {
            $seleccionado = $coincidenciasAlmacen->first();
        } else {
            $sucursales = $almacenes->pluck('sucursal')->unique()->filter(fn (string $sucursal): bool => str_contains($normalizado, $this->normalizar($sucursal)));
            if ($sucursales->count() === 1) {
                $almacenesSucursal = $almacenes->where('sucursal', $sucursales->first());
                if ($almacenesSucursal->count() > 1) {
                    return 'La sucursal '.$sucursales->first().' tiene varios almacenes. ¿En cuál harás el conteo? '.$almacenesSucursal->pluck('almacen')->implode(', ').'.';
                }
                $seleccionado = $almacenesSucursal->first();
            } else {
                $opciones = $almacenes->map(fn (object $almacen): string => $almacen->sucursal.' · '.$almacen->almacen)->unique()->implode('; ');

                return ($nuevo ? 'Abrí un registro de inventario. ' : '').'¿En qué sucursal y almacén haremos el conteo? Puedes elegir: '.$opciones.'.';
            }
        }

        $db->transaction(function () use ($db, $sesion, $seleccionado): void {
            $db->table('farmacia_inventarios')->where('id', $sesion->id)->update([
                'sucursal_id' => $seleccionado->sucursal_id, 'almacen_id' => $seleccionado->id, 'estado' => 'EN_CURSO', 'updated_at' => now(),
            ]);
            $existencias = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
                ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')
                ->leftJoin('lotes_items as l', 'l.item_id', '=', 'i.id')
                ->leftJoin('existencias_lote as e', function ($join) use ($seleccionado): void {
                    $join->on('e.lote_id', '=', 'l.id')->where('e.almacen_id', '=', $seleccionado->id);
                })
                ->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->where('t.activo', true)->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
                ->groupBy('i.id')->selectRaw('i.id as item_id, COALESCE(SUM(e.cantidad), 0) as cantidad')->get();
            foreach ($existencias as $existencia) {
                $db->table('farmacia_inventario_detalles')->insert([
                    'inventario_id' => $sesion->id, 'item_id' => $existencia->item_id,
                    'cantidad_esperada' => $existencia->cantidad, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return 'Listo, estamos en modo inventario para '.$seleccionado->sucursal.' · '.$seleccionado->almacen.'. Preparé el listado con el stock esperado y los vencimientos. Dime o dicta el código y la cantidad contada, por ejemplo: FAR002123, 10 unidades. Puedes descargar el Excel desde la subvista Inventario.';
    }

    private function registrarConteo(object $sesion, string $mensaje, $db, string $usuarioId): string
    {
        $clavePendiente = $this->claveConteoPendiente($usuarioId, $sesion->id);
        $pendiente = Cache::get($clavePendiente);
        $normalizado = $this->normalizar($mensaje);

        if (preg_match('/\b(?:confirmar|confirmo|correcto|si|sí)\b/u', $normalizado)) {
            return $pendiente ? $this->confirmarConteo($pendiente, $clavePendiente, $db, $usuarioId) : 'No hay ningún conteo pendiente de confirmación.';
        }
        if (preg_match('/\b(?:cancelar|cancelo|no|repetir)\b/u', $normalizado) && $pendiente) {
            Cache::forget($clavePendiente);

            return 'Entendido. No guardé ese conteo. Repite TOMI, código y cantidad cuando estés listo.';
        }

        $datos = $this->extraerConteo($mensaje);
        if (! $datos) {
            return $pendiente
                ? 'Tengo pendiente confirmar '.$pendiente['codigo'].', cantidad '.$this->formatearCantidad((float) $pendiente['cantidad']).'. Di TOMI confirmar o TOMI cancelar.'
                : 'No entendí el código o la cantidad. Repite: TOMI, código y cantidad; por ejemplo: TOMI FAR002123, 10.';
        }

        [$codigo, $cantidad] = $datos;
        $pendienteReemplazado = $pendiente && $pendiente['codigo'] !== $codigo ? $pendiente['codigo'] : null;
        $item = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->where('i.estado', 'ACTIVO')->where(function ($q) use ($codigo): void {
                $q->whereRaw('UPPER(i.codigo_barras) = ?', [$codigo])->orWhereRaw('UPPER(i.clave) = ?', [$codigo]);
                if (preg_match('/^ITM-0*(\d+)$/', $codigo, $id)) {
                    $q->orWhere('i.id', (int) $id[1]);
                }
            })->first(['i.id', 'i.nombre_comercial', 'i.marca', 'g.nombre as generico']);
        if (! $item) {
            Cache::forget($clavePendiente);

            return 'No encontré el código '.$codigo.'. Entendí ese código; repite solo el código del producto y después la cantidad.';
        }

        $detalle = $db->table('farmacia_inventario_detalles')->where('inventario_id', $sesion->id)->where('item_id', $item->id)->first();
        if (! $detalle) {
            $detalleId = $db->table('farmacia_inventario_detalles')->insertGetId([
                'inventario_id' => $sesion->id, 'item_id' => $item->id, 'cantidad_esperada' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $detalle = $db->table('farmacia_inventario_detalles')->where('id', $detalleId)->first();
        }

        $nombre = trim($item->nombre_comercial ?: $item->generico);
        $conteoPrevio = $detalle->cantidad_contada;
        if ($conteoPrevio !== null && (float) $conteoPrevio === $cantidad) {
            return 'Este producto ya estaba anotado con '.$this->formatearCantidad($cantidad).' unidades. No hice cambios.';
        }

        $pendiente = ['detalleId' => (int) $detalle->id, 'inventarioId' => (int) $sesion->id, 'itemId' => (int) $item->id,
            'codigo' => $codigo, 'nombre' => $nombre, 'cantidad' => $cantidad,
            'esperada' => (float) $detalle->cantidad_esperada, 'anterior' => $conteoPrevio === null ? null : (float) $conteoPrevio];
        Cache::put($clavePendiente, $pendiente, now()->addMinutes(3));

        $reemplazo = $conteoPrevio === null ? '' : ' Ya estaba anotado en '.$this->formatearCantidad((float) $conteoPrevio).' unidades.';

        $notaPendiente = $pendienteReemplazado ? ' Dejé sin guardar el conteo pendiente anterior de '.$pendienteReemplazado.'.' : '';

        return 'Entendí código '.$codigo.', '.$nombre.', cantidad '.$this->formatearCantidad($cantidad).' unidades.'.$reemplazo.$notaPendiente.' ¿Confirmas? Di TOMI confirmar o TOMI cancelar.';
    }

    private function extraerConteo(string $mensaje): ?array
    {
        $mensaje = $this->normalizar($mensaje);
        $palabrasNumero = 'cero|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte';
        $mensaje = preg_replace_callback('/\b('.$palabrasNumero.')\b/u', fn (array $coincidencia): string => (string) $this->numeroHablado($coincidencia[1]), $mensaje) ?? $mensaje;
        $mensaje = preg_replace_callback('/\b([a-z]{2,})[ -]+((?:\d[ -]*){3,})\b/iu', fn (array $coincidencia): string => $coincidencia[1].preg_replace('/\D/', '', $coincidencia[2]), $mensaje) ?? $mensaje;
        if (! preg_match('/\b(?:codigo\s*)?([a-z0-9-]{3,})\b.*?\b(?:cantidad\s*)?(\d+(?:[.,]\d+)?)\s*(?:unidades?|uds?|piezas?)?\b/iu', $mensaje, $datos)) {
            return null;
        }
        $codigo = mb_strtoupper(rtrim($datos[1], '-'));
        if (! preg_match('/\d{3,}|^\d{3,}$/', $codigo)) {
            return null;
        }

        return [$codigo, (float) str_replace(',', '.', $datos[2])];
    }

    private function confirmarConteo(array $pendiente, string $clavePendiente, $db, string $usuarioId): string
    {
        try {
            $actualizado = $db->table('farmacia_inventario_detalles')->where('id', $pendiente['detalleId'])
                ->update(['cantidad_contada' => $pendiente['cantidad'], 'contado_en' => now(), 'updated_at' => now()]);
        } catch (\Throwable) {
            return 'No pude guardar el conteo de '.$pendiente['codigo'].'. El dato sigue pendiente; intenta confirmar otra vez o revisa tu conexión.';
        }
        if (! $actualizado) {
            return 'No pude guardar el conteo de '.$pendiente['codigo'].'. El dato sigue pendiente; intenta confirmar otra vez.';
        }
        Log::info('TOMI confirmó un conteo de inventario', [
            'usuario_id' => $usuarioId, 'inventario_id' => $pendiente['inventarioId'], 'item_id' => $pendiente['itemId'],
            'codigo' => $pendiente['codigo'], 'cantidad_anterior' => $pendiente['anterior'], 'cantidad_nueva' => $pendiente['cantidad'],
        ]);
        Cache::forget($clavePendiente);
        $diferencia = $pendiente['cantidad'] - $pendiente['esperada'];
        $resultado = $diferencia < 0 ? 'faltante de '.$this->formatearCantidad(abs($diferencia)).' unidades'
            : ($diferencia > 0 ? 'sobrante de '.$this->formatearCantidad($diferencia).' unidades' : 'correcto, coincide con la cantidad esperada');

        return 'Anotado. Código '.$pendiente['codigo'].'. '.$pendiente['nombre'].'. Cantidad anotada '.$this->formatearCantidad($pendiente['cantidad']).' unidades. Cantidad esperada '.$this->formatearCantidad($pendiente['esperada']).' unidades. Diferencia: '.$resultado.'.';
    }

    private function siguientePendiente(object $sesion, $db): string
    {
        $item = $db->table('farmacia_inventario_detalles as d')->join('items_inventario as i', 'i.id', '=', 'd.item_id')
            ->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')->where('d.inventario_id', $sesion->id)
            ->whereNull('d.cantidad_contada')->orderBy('g.nombre')->orderBy('i.nombre_comercial')
            ->first(['i.id', 'i.codigo_barras', 'i.clave', 'i.nombre_comercial', 'g.nombre as generico', 'd.cantidad_esperada']);
        if (! $item) {
            return 'No quedan productos pendientes. Puedes decir TOMI finalizar inventario.';
        }
        $codigo = $item->codigo_barras ?: ($item->clave ?: 'ITM-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT));
        $nombre = $item->nombre_comercial ?: $item->generico;

        return 'Siguiente pendiente: código '.$codigo.', '.$nombre.'. Cantidad esperada '.$this->formatearCantidad((float) $item->cantidad_esperada).' unidades. Di TOMI, '.$codigo.' y la cantidad contada.';
    }

    private function claveConteoPendiente(string $usuarioId, int|string $inventarioId): string
    {
        return 'farmacia:tomi:conteo:'.$usuarioId.':'.$inventarioId;
    }

    private function finalizar(object $sesion, $db): string
    {
        $pendientes = $db->table('farmacia_inventario_detalles')->where('inventario_id', $sesion->id)->whereNull('cantidad_contada')->count();
        $db->table('farmacia_inventarios')->where('id', $sesion->id)->update(['estado' => 'FINALIZADO', 'finalizado_en' => now(), 'updated_at' => now()]);
        $this->inventarioFinalizadoId = (int) $sesion->id;

        return $pendientes > 0
            ? 'Inventario finalizado. Quedaron '.$pendientes.' productos sin contar; aparecerán como pendientes en el Excel. El archivo está listo para descargar.'
            : 'Inventario finalizado. El Excel con el conteo, faltantes y sobrantes está listo para descargar.';
    }

    private function sesionActiva($db, string $usuarioId): ?object
    {
        return $db->table('farmacia_inventarios as f')->leftJoin('sucursales_inventario as s', 's.id', '=', 'f.sucursal_id')
            ->leftJoin('almacenes_inventario as a', 'a.id', '=', 'f.almacen_id')->where('f.iniciado_por', $usuarioId)
            ->whereIn('f.estado', ['ESPERANDO_ALMACEN', 'EN_CURSO'])->orderByDesc('f.id')
            ->first(['f.id', 'f.estado', 'f.sucursal_id', 'f.almacen_id', 's.nombre as sucursal', 'a.nombre as almacen']);
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto));
    }

    private function numeroHablado(string $numero): int
    {
        $numeros = ['cero' => 0, 'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19, 'veinte' => 20];

        return $numeros[$numero] ?? 0;
    }

    private function formatearCantidad(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 3, ',', '.'), '0'), ',');
    }
}
