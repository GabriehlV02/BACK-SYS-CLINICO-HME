<?php

namespace App\Flows\Farmacia;

use App\Flows\Autenticacion\AccesoCompartido;

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

        return $this->registrarConteo($sesion, $mensaje, $db);
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

    private function registrarConteo(object $sesion, string $mensaje, $db): string
    {
        $mensaje = preg_replace_callback('/\b([a-z]{2,})[ -]+((?:\d[ -]*){3,})\b/iu', fn (array $coincidencia): string => $coincidencia[1].preg_replace('/\D/', '', $coincidencia[2]), $mensaje) ?? $mensaje;
        if (! preg_match('/\b([a-z]{2,}\d{3,})\b\s*(?:,|:|;|-)?\s*(\d+(?:[.,]\d+)?)\s*(?:unidades?|uds?|piezas?)?\b/iu', $mensaje, $datos)) {
            if (preg_match('/\b([a-z]{2,}\d{3,})\b.*?\b(cero|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte)\s*(?:unidades?|uds?|piezas?)\b/iu', $this->normalizar($mensaje), $hablado)) {
                $datos = [$hablado[0], $hablado[1], (string) $this->numeroHablado($hablado[2])];
            } else {
                return 'Seguimos en modo inventario para '.$sesion->sucursal.' · '.$sesion->almacen.'. Dicta cada conteo como código y cantidad, por ejemplo: FAR002123, 10 unidades.';
            }
        }

        if (! isset($datos[1], $datos[2])) {
            return 'Seguimos en modo inventario para '.$sesion->sucursal.' · '.$sesion->almacen.'. Dicta cada conteo como código y cantidad, por ejemplo: FAR002123, 10 unidades.';
        }

        $codigo = mb_strtoupper($datos[1]);
        $cantidad = (float) str_replace(',', '.', $datos[2]);
        $item = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
            ->where('i.estado', 'ACTIVO')->where(function ($q) use ($codigo): void {
                $q->whereRaw('UPPER(i.codigo_barras) = ?', [$codigo])->orWhereRaw('UPPER(i.clave) = ?', [$codigo]);
                if (preg_match('/^ITM-0*(\d+)$/', $codigo, $id)) {
                    $q->orWhere('i.id', (int) $id[1]);
                }
            })->first(['i.id', 'i.nombre_comercial', 'i.marca', 'g.nombre as generico']);
        if (! $item) {
            return 'No encontré el código '.$codigo.' en el catálogo. Revisa el código y vuelve a dictarlo junto con la cantidad.';
        }

        $detalle = $db->table('farmacia_inventario_detalles')->where('inventario_id', $sesion->id)->where('item_id', $item->id)->first();
        if (! $detalle) {
            $detalleId = $db->table('farmacia_inventario_detalles')->insertGetId([
                'inventario_id' => $sesion->id, 'item_id' => $item->id, 'cantidad_esperada' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $detalle = $db->table('farmacia_inventario_detalles')->where('id', $detalleId)->first();
        }

        $db->table('farmacia_inventario_detalles')->where('id', $detalle->id)->update([
            'cantidad_contada' => $cantidad, 'contado_en' => now(), 'updated_at' => now(),
        ]);
        $diferencia = $cantidad - (float) $detalle->cantidad_esperada;
        $resultado = $diferencia < 0 ? 'faltan '.number_format(abs($diferencia), 3, ',', '.')
            : ($diferencia > 0 ? 'sobran '.number_format($diferencia, 3, ',', '.') : 'coincide con el stock esperado');

        return 'Anotado: '.$codigo.' · '.$item->generico.' ('.$item->marca.') — stock verificado '.$this->formatearCantidad($cantidad).'; esperado '.$this->formatearCantidad((float) $detalle->cantidad_esperada).', '.$resultado.'.';
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
