<?php

namespace App\Flows\Farmacia\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipArchive;

class FarmaciaInventariosController
{
    public function activo(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $db = $acceso->conexion();
        $inventario = $db->table('farmacia_inventarios as f')->leftJoin('sucursales_inventario as s', 's.id', '=', 'f.sucursal_id')
            ->leftJoin('almacenes_inventario as a', 'a.id', '=', 'f.almacen_id')->where('f.iniciado_por', $usuario->id)
            ->whereIn('f.estado', ['ESPERANDO_ALMACEN', 'EN_CURSO'])->orderByDesc('f.id')
            ->first(['f.id', 'f.sucursal_id', 'f.almacen_id', 'f.estado', 'f.iniciado_en', 'f.finalizado_en', 's.nombre as sucursal', 'a.nombre as almacen']);

        return response()->json(['data' => $inventario ? $this->resumen($db, $inventario) : null]);
    }

    public function index(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $filtros = $request->validate(['buscar' => ['nullable', 'string', 'max:120'], 'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde']]);
        $db = $acceso->conexion();
        $permitidos = $acceso->almacenesPermitidos();
        $almacenes = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.activo', true)->where('s.activo', true)->when($permitidos !== null, fn ($q) => $q->whereIn('a.id', $permitidos))
            ->orderBy('s.nombre')->orderBy('a.nombre')->get(['a.id', 'a.nombre as almacen', 'a.sucursal_id', 's.nombre as sucursal']);
        $usuarioId = $request->user()->id;
        $registros = $db->table('farmacia_inventarios as f')->leftJoin('sucursales_inventario as s', 's.id', '=', 'f.sucursal_id')
            ->leftJoin('almacenes_inventario as a', 'a.id', '=', 'f.almacen_id')
            ->when($permitidos !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereIn('f.almacen_id', $permitidos)->orWhere(fn ($pending) => $pending->whereNull('f.almacen_id')->where('f.iniciado_por', $usuarioId))))
            ->when(! empty($filtros['desde']), fn ($q) => $q->whereDate('f.iniciado_en', '>=', $filtros['desde']))
            ->when(! empty($filtros['hasta']), fn ($q) => $q->whereDate('f.iniciado_en', '<=', $filtros['hasta']))
            ->when(! empty($filtros['buscar']), function ($q) use ($filtros): void {
                $termino = '%'.$filtros['buscar'].'%';
                $q->where(fn ($sub) => $sub->whereLike('s.nombre', $termino)->orWhereLike('a.nombre', $termino)->orWhereLike('f.estado', $termino));
            })->orderByDesc('f.iniciado_en')->get(['f.id', 'f.sucursal_id', 'f.almacen_id', 'f.estado', 'f.iniciado_en', 'f.finalizado_en', 's.nombre as sucursal', 'a.nombre as almacen']);

        $inventarios = $registros->map(fn (object $inventario): array => $this->resumen($db, $inventario));

        return response()->json(['data' => $inventarios, 'almacenes' => $almacenes->map(fn (object $almacen): array => [
            'id' => (int) $almacen->id, 'sucursalId' => (int) $almacen->sucursal_id, 'sucursal' => $almacen->sucursal, 'nombre' => $almacen->almacen,
        ])]);
    }

    public function show(int $inventario, AccesoCompartido $acceso): JsonResponse
    {
        $registro = $this->registro($inventario, $acceso);
        $db = $acceso->conexion();
        $resumen = $this->resumen($db, $registro);
        $resumen['items'] = $db->table('farmacia_inventario_detalles as d')->join('items_inventario as i', 'i.id', '=', 'd.item_id')
            ->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')->where('d.inventario_id', $inventario)
            ->orderBy('g.nombre')->orderBy('i.nombre_comercial')->get(['d.id', 'd.item_id', 'd.cantidad_esperada', 'd.cantidad_contada', 'i.clave', 'i.codigo_barras', 'i.nombre_comercial as nombre', 'i.marca', 'i.presentacion', 'g.nombre as generico'])
            ->map(fn (object $item): array => ['id' => (int) $item->id, 'itemId' => (int) $item->item_id, 'codigo' => $item->codigo_barras ?: ($item->clave ?: 'ITM-'.str_pad((string) $item->item_id, 6, '0', STR_PAD_LEFT)), 'nombre' => $item->nombre,
                'generico' => $item->generico, 'marca' => $item->marca, 'presentacion' => $item->presentacion,
                'esperada' => (float) $item->cantidad_esperada, 'contada' => $item->cantidad_contada === null ? null : (float) $item->cantidad_contada]);

        return response()->json(['data' => $resumen]);
    }

    public function store(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $datos = $request->validate(['almacen_id' => ['required', 'integer', 'exists:usuarios.almacenes_inventario,id']]);
        $db = $acceso->conexion();
        $almacenId = (int) $datos['almacen_id'];
        $permitidos = $acceso->almacenesPermitidos();
        abort_unless($permitidos === null || in_array($almacenId, $permitidos, true), 403);
        $almacen = $db->table('almacenes_inventario as a')->join('sucursales_inventario as s', 's.id', '=', 'a.sucursal_id')
            ->where('a.id', $almacenId)->where('a.activo', true)->where('s.activo', true)->first(['a.id', 'a.sucursal_id']);
        abort_unless($almacen, 404);
        abort_if($db->table('farmacia_inventarios')->where('almacen_id', $almacenId)->where('estado', 'EN_CURSO')->exists(), 409, 'Ya hay un inventario en curso para este almacén.');

        $id = $db->transaction(function () use ($db, $almacen, $almacenId, $usuario): int {
            $id = $db->table('farmacia_inventarios')->insertGetId([
                'sucursal_id' => $almacen->sucursal_id, 'almacen_id' => $almacenId, 'estado' => 'EN_CURSO',
                'iniciado_en' => now(), 'iniciado_por' => $usuario->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $existencias = $db->table('items_inventario as i')->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')
                ->join('tipos_inventario as t', 't.id', '=', 'g.tipo_id')->leftJoin('lotes_items as l', 'l.item_id', '=', 'i.id')
                ->leftJoin('existencias_lote as e', function ($join) use ($almacenId): void {
                    $join->on('e.lote_id', '=', 'l.id')->where('e.almacen_id', '=', $almacenId);
                })->where('i.estado', 'ACTIVO')->where('g.estado', 'ACTIVO')->where('t.activo', true)->whereIn('t.tipo_base', ['PRODUCTO', 'INSUMO'])
                ->groupBy('i.id')->selectRaw('i.id as item_id, COALESCE(SUM(e.cantidad), 0) as cantidad')->get();
            foreach ($existencias as $existencia) {
                $db->table('farmacia_inventario_detalles')->insert([
                    'inventario_id' => $id, 'item_id' => $existencia->item_id, 'cantidad_esperada' => $existencia->cantidad,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });

        return $this->show($id, $acceso)->setStatusCode(201);
    }

    public function contar(int $inventario, int $detalle, Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $datos = $request->validate(['cantidad' => ['required', 'numeric', 'min:0', 'max:999999999999']]);
        $registro = $this->registro($inventario, $acceso);
        abort_unless($registro->estado === 'EN_CURSO', 409, 'Este inventario ya fue finalizado.');
        $actualizado = $acceso->conexion()->table('farmacia_inventario_detalles')->where('inventario_id', $inventario)->where('id', $detalle)
            ->update(['cantidad_contada' => $datos['cantidad'], 'contado_en' => now(), 'updated_at' => now()]);
        abort_unless($actualizado, 404);

        return $this->show($inventario, $acceso);
    }

    public function finalizar(int $inventario, Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario, 401);
        $acceso->exigir($usuario->id, 'INVENTARIO_GESTIONAR');
        $registro = $this->registro($inventario, $acceso);
        abort_unless($registro->estado === 'EN_CURSO', 409, 'Este inventario ya fue finalizado.');
        $db = $acceso->conexion();
        $pendientes = $db->table('farmacia_inventario_detalles')->where('inventario_id', $inventario)->whereNull('cantidad_contada')->count();
        abort_if($pendientes > 0, 422, 'Cuenta todos los ítems antes de finalizar el inventario.');
        $db->table('farmacia_inventarios')->where('id', $inventario)->update(['estado' => 'FINALIZADO', 'finalizado_en' => now(), 'updated_at' => now()]);

        return $this->show($inventario, $acceso);
    }

    public function excel(int $inventario, AccesoCompartido $acceso)
    {
        $registro = $this->registro($inventario, $acceso);
        $db = $acceso->conexion();
        $items = $db->table('farmacia_inventario_detalles as d')->join('items_inventario as i', 'i.id', '=', 'd.item_id')
            ->join('productos_base as g', 'g.id', '=', 'i.producto_base_id')->where('d.inventario_id', $inventario)
            ->orderBy('g.nombre')->orderBy('i.nombre_comercial')
            ->get(['d.item_id', 'd.cantidad_esperada', 'd.cantidad_contada', 'i.clave', 'i.codigo_barras', 'i.nombre_comercial', 'i.marca', 'i.presentacion', 'g.nombre as generico']);
        $lotes = $db->table('lotes_items as l')->join('existencias_lote as e', 'e.lote_id', '=', 'l.id')
            ->where('e.almacen_id', $registro->almacen_id)->whereIn('l.item_id', $items->pluck('item_id'))
            ->orderBy('l.vencimiento')->get(['l.item_id', 'l.numero', 'l.vencimiento', 'e.cantidad'])->groupBy('item_id');
        $filas = [['Código', 'Producto', 'Nombre comercial', 'Marca', 'Presentación', 'Stock esperado', 'Stock verificado', 'Diferencia', 'Lotes y fechas de vencimiento', 'Estado del conteo']];
        foreach ($items as $item) {
            $esperada = (float) $item->cantidad_esperada;
            $contada = $item->cantidad_contada === null ? null : (float) $item->cantidad_contada;
            $codigo = $item->codigo_barras ?: ($item->clave ?: 'ITM-'.str_pad((string) $item->item_id, 6, '0', STR_PAD_LEFT));
            $lotesItem = ($lotes[$item->item_id] ?? collect())->map(fn (object $lote): string => trim(($lote->numero ?: 'Lote sin número').' · vence '.($lote->vencimiento ?: 'sin fecha').' · stock '.number_format((float) $lote->cantidad, 3, ',', '.')))->implode(' | ');
            $filas[] = [$codigo, $item->generico, $item->nombre_comercial, $item->marca, $item->presentacion, $esperada,
                $contada, $contada === null ? null : $contada - $esperada, $lotesItem ?: 'Sin lotes registrados', $contada === null ? 'PENDIENTE' : 'CONTADO'];
        }

        $temporal = tempnam(sys_get_temp_dir(), 'tomi-inventario-');
        abort_unless($temporal !== false, 500, 'No se pudo preparar el archivo de Excel.');
        unlink($temporal);
        $ruta = $temporal.'.xlsx';
        $this->crearLibroExcel($ruta, $filas);
        $nombre = 'inventario-'.Str::slug(($registro->sucursal ?? 'sucursal').'-'.($registro->almacen ?? 'almacen')).'-'.$inventario.'.xlsx';

        return response()->download($ruta, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function crearLibroExcel(string $ruta, array $filas): void
    {
        $archivo = new ZipArchive;
        abort_unless($archivo->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'No se pudo generar el archivo de Excel.');
        $ultimoRenglon = count($filas);
        $ultimaColumna = $this->columnaExcel(count($filas[0]));
        $sheetRows = '';
        foreach ($filas as $indiceFila => $fila) {
            $numeroFila = $indiceFila + 1;
            $celdas = '';
            foreach ($fila as $indiceColumna => $valor) {
                $referencia = $this->columnaExcel($indiceColumna + 1).$numeroFila;
                if (is_int($valor) || is_float($valor)) {
                    $celdas .= '<c r="'.$referencia.'"'.($indiceFila === 0 ? ' s="1"' : '').'><v>'.$valor.'</v></c>';
                } else {
                    $texto = htmlspecialchars((string) ($valor ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $celdas .= '<c r="'.$referencia.'" t="inlineStr"'.($indiceFila === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.$texto.'</t></is></c>';
                }
            }
            $sheetRows .= '<row r="'.$numeroFila.'">'.$celdas.'</row>';
        }
        $archivo->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $archivo->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archivo->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventario" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archivo->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $archivo->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF087FC0"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$ultimaColumna.$ultimoRenglon.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><sheetData>'.$sheetRows.'</sheetData><autoFilter ref="A1:'.$ultimaColumna.$ultimoRenglon.'"/></worksheet>';
        $archivo->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $archivo->close();
    }

    private function columnaExcel(int $numero): string
    {
        $columna = '';
        while ($numero > 0) {
            $resto = ($numero - 1) % 26;
            $columna = chr(65 + $resto).$columna;
            $numero = intdiv($numero - 1, 26);
        }

        return $columna;
    }

    private function registro(int $inventario, AccesoCompartido $acceso): object
    {
        $permitidos = $acceso->almacenesPermitidos();

        $usuarioId = request()->user()->id;

        return $acceso->conexion()->table('farmacia_inventarios as f')->leftJoin('sucursales_inventario as s', 's.id', '=', 'f.sucursal_id')
            ->leftJoin('almacenes_inventario as a', 'a.id', '=', 'f.almacen_id')->where('f.id', $inventario)
            ->when($permitidos !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereIn('f.almacen_id', $permitidos)->orWhere(fn ($pending) => $pending->whereNull('f.almacen_id')->where('f.iniciado_por', $usuarioId))))
            ->first(['f.*', 's.nombre as sucursal', 'a.nombre as almacen']) ?? abort(404);
    }

    private function resumen($db, object $inventario): array
    {
        $lineas = $db->table('farmacia_inventario_detalles')->where('inventario_id', $inventario->id)->get();
        $esperada = (float) $lineas->sum('cantidad_esperada');
        $contada = (float) $lineas->whereNotNull('cantidad_contada')->sum('cantidad_contada');
        $faltantes = (float) $lineas->filter(fn (object $linea): bool => $linea->cantidad_contada !== null && (float) $linea->cantidad_contada < (float) $linea->cantidad_esperada)
            ->sum(fn (object $linea): float => (float) $linea->cantidad_esperada - (float) $linea->cantidad_contada);
        $sobrantes = (float) $lineas->filter(fn (object $linea): bool => $linea->cantidad_contada !== null && (float) $linea->cantidad_contada > (float) $linea->cantidad_esperada)
            ->sum(fn (object $linea): float => (float) $linea->cantidad_contada - (float) $linea->cantidad_esperada);

        return ['id' => (int) $inventario->id, 'sucursal' => $inventario->sucursal ?? 'Sucursal pendiente de selección', 'almacen' => $inventario->almacen ?? 'Almacén pendiente de selección', 'estado' => $inventario->estado,
            'inicio' => $inventario->iniciado_en, 'finalizado' => $inventario->finalizado_en, 'cantidadEsperada' => $esperada,
            'cantidadContada' => $contada, 'faltantes' => $faltantes, 'sobrantes' => $sobrantes,
            'itemsTotal' => $lineas->count(), 'itemsContados' => $lineas->whereNotNull('cantidad_contada')->count()];
    }
}
