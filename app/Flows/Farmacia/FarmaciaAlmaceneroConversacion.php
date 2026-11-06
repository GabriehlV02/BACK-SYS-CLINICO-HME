<?php

namespace App\Flows\Farmacia;

use App\Flows\Autenticacion\AccesoCompartido;

class FarmaciaAlmaceneroConversacion
{
    public function responder(string $mensaje, object $usuario, AccesoCompartido $acceso): ?string
    {
        $db = $acceso->conexion();
        if (! $db->getSchemaBuilder()->hasTable('farmacia_almacenador_sesiones')) {
            return preg_match('/\bmodo almacenero\b/u', $this->normalizar($mensaje))
                ? 'El modo almacenero está preparado, pero falta aplicar la migración de su sesión en la base contable.'
                : null;
        }

        $normalizado = $this->normalizar($mensaje);
        $sesion = $db->table('farmacia_almacenador_sesiones')->where('usuario_id', $usuario->id)
            ->whereIn('estado', ['ESPERANDO_ALMACEN', 'EN_CURSO'])->orderByDesc('id')->first();

        if (preg_match('/\bmodo inventario\b/u', $normalizado)) {
            if ($sesion) {
                $db->table('farmacia_almacenador_sesiones')->where('id', $sesion->id)->update(['estado' => 'CERRADO', 'updated_at' => now()]);
            }

            return null;
        }

        if (preg_match('/\b(?:termina|terminar|finaliza|finalizar|salir|cierra|cerrar) (?:el )?modo almacenero\b/u', $normalizado)) {
            if (! $sesion) {
                return 'No tienes un modo almacenero activo para cerrar.';
            }
            $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
            $db->table('farmacia_almacenador_sesiones')->where('id', $sesion->id)->update(['estado' => 'CERRADO', 'updated_at' => now()]);

            return 'Cerré el modo almacenero. Las ubicaciones que registramos quedaron guardadas.';
        }

        if (preg_match('/\bmodo almacenero\b/u', $normalizado)) {
            $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
            if ($sesion) {
                $db->table('farmacia_almacenador_sesiones')->where('id', $sesion->id)->update(['sucursal_id' => null, 'almacen_id' => null, 'estado' => 'ESPERANDO_ALMACEN', 'iniciado_en' => null, 'updated_at' => now()]);
                $sesion->estado = 'ESPERANDO_ALMACEN';

                return $this->elegirAlmacen($sesion, $normalizado, $acceso);
            }
            $id = $db->table('farmacia_almacenador_sesiones')->insertGetId([
                'usuario_id' => $usuario->id, 'estado' => 'ESPERANDO_ALMACEN', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->elegirAlmacen((object) ['id' => $id], $normalizado, $acceso);
        }

        if (! $sesion) {
            return null;
        }
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        if ($sesion->estado === 'ESPERANDO_ALMACEN') {
            return $this->elegirAlmacen($sesion, $normalizado, $acceso);
        }

        return $this->asignarUbicacion($sesion, $mensaje, $acceso);
    }

    private function elegirAlmacen(object $sesion, string $normalizado, AccesoCompartido $acceso): string
    {
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        if ($permitidos === []) {
            return 'Tu usuario no tiene almacenes asignados para organizar.';
        }
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.activo', true)->where('s.activo', true)->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))
            ->orderBy('s.nombre')->orderBy('a.nombre')->get(['a.id', 'a.sucursal_id', 'a.nombre as almacen', 's.nombre as sucursal']);
        if ($almacenes->isEmpty()) {
            return 'No encuentro sucursales y almacenes activos a los que tengas acceso.';
        }

        $coincidencias = $almacenes->filter(fn (object $a): bool => str_contains($normalizado, $this->normalizar($a->almacen)));
        if ($coincidencias->count() === 1) {
            $seleccionado = $coincidencias->first();
        } else {
            $sucursales = $almacenes->pluck('sucursal')->unique()->filter(fn (string $n): bool => str_contains($normalizado, $this->normalizar($n)));
            if ($sucursales->count() !== 1) {
                $opciones = $almacenes->map(fn (object $a): string => $a->sucursal.' · '.$a->almacen)->unique()->implode('; ');

                return '¿En qué sucursal y almacén registraré las ubicaciones? Puedes decir el nombre completo. Opciones: '.$opciones.'.';
            }
            $deSucursal = $almacenes->where('sucursal', $sucursales->first());
            if ($deSucursal->count() !== 1) {
                return 'La sucursal '.$sucursales->first().' tiene varios almacenes. ¿En cuál organizarás los productos? '.$deSucursal->pluck('almacen')->implode(', ').'.';
            }
            $seleccionado = $deSucursal->first();
        }

        $db->table('farmacia_almacenador_sesiones')->where('id', $sesion->id)->update([
            'sucursal_id' => $seleccionado->sucursal_id, 'almacen_id' => $seleccionado->id, 'estado' => 'EN_CURSO',
            'iniciado_en' => now(), 'updated_at' => now(),
        ]);

        return 'Modo almacenero activo en '.$seleccionado->sucursal.' · '.$seleccionado->almacen.'. Dicta el código del producto, el estante y la repisa; por ejemplo: FAR002123, estante 2, repisa 1. Puedes cambiar de almacén indicando “modo almacenero” otra vez.';
    }

    private function asignarUbicacion(object $sesion, string $mensaje, AccesoCompartido $acceso): string
    {
        $db = $acceso->conexion();
        $mensajeCodigo = preg_replace_callback('/\b([a-z]{2,})[ -]+((?:\d[ -]*){3,})\b/iu', fn (array $m): string => $m[1].preg_replace('/\D/', '', $m[2]), $mensaje) ?? $mensaje;
        if (! preg_match('/\b([a-z]{2,}\d{3,})\b/iu', $mensajeCodigo, $coincidencia)) {
            return 'No identifiqué el código del producto. Dicta el código, el estante y la repisa, por ejemplo: FAR002123, estante 2, repisa 1.';
        }
        $codigo = mb_strtoupper($coincidencia[1]);
        $item = $db->table('items_inventario')->where('estado', 'ACTIVO')->where(function ($q) use ($codigo): void {
            $q->whereRaw('UPPER(clave) = ?', [$codigo])->orWhereRaw('UPPER(codigo_barras) = ?', [$codigo]);
            if (preg_match('/^ITM-0*(\d+)$/', $codigo, $id)) {
                $q->orWhere('id', (int) $id[1]);
            }
        })->first(['id', 'nombre_comercial', 'marca', 'clave', 'codigo_barras']);
        if (! $item) {
            return 'No encontré el código '.$codigo.' en el catálogo activo. Verifica el código y vuelve a dictarlo.';
        }
        $espacios = $db->table('farmacia_espacios')->where('almacen_id', $sesion->almacen_id)->get(['id', 'tipo', 'nombre']);
        $texto = $this->normalizar($mensaje);
        $espacio = $espacios->first(fn (object $e): bool => str_contains($texto, $this->normalizar($e->nombre)));
        if (! $espacio && preg_match('/\bestante\s*(\d+)\b/u', $texto, $numeroEstante)) {
            $espacio = $espacios->first(fn (object $e): bool => $e->tipo === 'ESTANTE' && preg_match('/\b'.preg_quote($numeroEstante[1], '/').'\b/u', $this->normalizar($e->nombre)) === 1);
        }
        if (! $espacio) {
            return 'Encontré '.$codigo.', pero necesito el estante configurado en la sucursal. Dime, por ejemplo: estante 2.';
        }
        $repisas = $db->table('farmacia_repisas')->where('espacio_id', $espacio->id)->orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'orden']);
        $repisa = $repisas->first(fn (object $r): bool => str_contains($texto, $this->normalizar($r->nombre)));
        if (! $repisa && preg_match('/\brepisa\s*(\d+)\b/u', $texto, $numeroRepisa)) {
            $indice = (int) $numeroRepisa[1] - 1;
            $repisa = $repisas->first(fn (object $r): bool => (int) $r->orden === $indice)
                ?? $repisas->values()->get($indice);
        }
        if (! $repisa) {
            return 'Encontré '.$codigo.' en '.$espacio->nombre.', pero no identifiqué una repisa válida. Dime su nombre o número.';
        }

        $stock = $db->table('existencias_lote as x')->join('lotes_items as l', 'l.id', '=', 'x.lote_id')
            ->where('x.almacen_id', $sesion->almacen_id)->where('l.item_id', $item->id)->where('x.cantidad', '>', 0)->exists();
        if (! $stock) {
            return 'No puedo asignar '.$codigo.' porque no tiene stock en '.$sesion->almacen.'.';
        }
        $ubicacion = mb_substr($espacio->nombre.' · '.$repisa->nombre, 0, 120);
        $llave = ['item_id' => $item->id, 'almacen_id' => $sesion->almacen_id];
        $registro = ['repisa_id' => $repisa->id, 'ubicacion' => $ubicacion, 'updated_at' => now()];
        if ($db->table('item_almacen_ubicaciones')->where($llave)->exists()) {
            $db->table('item_almacen_ubicaciones')->where($llave)->update($registro);
        } else {
            $db->table('item_almacen_ubicaciones')->insert($llave + $registro + ['created_at' => now()]);
        }

        return 'Ubicación guardada: '.$codigo.' · '.$item->nombre_comercial.($item->marca ? ' ('.$item->marca.')' : '').' → '.$sesion->sucursal.' · '.$sesion->almacen.' · '.$ubicacion.'.';
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto));
    }
}
