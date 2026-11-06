<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use Throwable;
use XMLReader;
use ZipArchive;

class ImportarDiagnosticosCie11 extends Command
{
    protected $signature = 'diagnosticos:importar-cie11 {archivo : Ruta al archivo XLSX de la tabulación CIE-11 en español}';

    protected $description = 'Importa las categorías codificadas de la tabulación CIE-11 sin duplicarlas';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        $archivo = $this->argument('archivo');
        $zip = new ZipArchive;
        if (! is_file($archivo) || $zip->open($archivo) !== true) {
            $this->error('No se pudo abrir el archivo XLSX indicado.');

            return self::FAILURE;
        }
        try {
            $compartidas = $zip->getFromName('xl/sharedStrings.xml');
            $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($compartidas === false || $hoja === false) {
                throw new \RuntimeException('El archivo no contiene la hoja o los textos esperados.');
            }
            $textos = [];
            $lector = new XMLReader;
            $lector->XML($compartidas, null, LIBXML_NONET | LIBXML_COMPACT);
            while ($lector->read()) {
                if ($lector->nodeType === XMLReader::ELEMENT && $lector->localName === 'si') {
                    $elemento = new SimpleXMLElement($lector->readOuterXml(), LIBXML_NONET);
                    $textos[] = implode('', array_map('strval', $elemento->xpath('.//*[local-name()="t"]')));
                }
            }
            $lector->close();
            unset($compartidas);
            $lector = new XMLReader;
            $lector->XML($hoja, null, LIBXML_NONET | LIBXML_COMPACT);
            $vistos = [];
            $contador = 0;
            $sinTraduccion = 0;
            $omitidos = 0;
            $encabezado = false;
            $version = null;
            $lote = [];
            $momento = now();
            DB::transaction(function () use ($lector, $textos, &$vistos, &$contador, &$sinTraduccion, &$omitidos, &$encabezado, &$version, &$lote, $momento): void {
                while ($lector->read()) {
                    if ($lector->nodeType !== XMLReader::ELEMENT || $lector->localName !== 'row') {
                        continue;
                    }
                    $elemento = new SimpleXMLElement($lector->readOuterXml(), LIBXML_NONET);
                    $fila = [];
                    foreach ($elemento->xpath('./*[local-name()="c"]') as $celda) {
                        $atributos = $celda->attributes();
                        preg_match('/^[A-Z]+/', (string) $atributos['r'], $columna);
                        $nodos = $celda->xpath('./*[local-name()="v"]');
                        $valor = (string) ($nodos[0] ?? '');
                        $fila[$columna[0]] = (string) ($atributos['t'] ?? '') === 's' ? ($textos[(int) $valor] ?? '') : $valor;
                    }
                    if (! $encabezado) {
                        if (($fila['A'] ?? '') !== 'Foundation URI' || ($fila['B'] ?? '') !== 'Linearization URI' || ($fila['C'] ?? '') !== 'Code' || ($fila['F'] ?? '') !== 'Title' || ($fila['G'] ?? '') !== 'ClassKind' || ($fila['T'] ?? '') !== 'Parent') {
                            throw new \RuntimeException('La estructura del archivo no coincide con la tabulación CIE-11 esperada.');
                        }
                        $version = $fila['U'] ?? null;
                        $encabezado = true;

                        continue;
                    }
                    if (($fila['G'] ?? '') !== 'category') {
                        $omitidos++;

                        continue;
                    }
                    $codigo = trim($fila['C'] ?? '');
                    $uri = trim($fila['B'] ?? '');
                    $nombreEspanol = trim($fila['F'] ?? '');
                    $nombreIngles = trim($fila['E'] ?? '');
                    $idioma = $nombreEspanol !== '' ? 'es' : 'en';
                    $nombreOriginal = $nombreEspanol !== '' ? $nombreEspanol : $nombreIngles;
                    if ($codigo === '' || $uri === '' || $nombreOriginal === '' || isset($vistos[$codigo])) {
                        throw new \RuntimeException('Hay una categoría sin código, URI o título, o con código repetido: '.$codigo);
                    }
                    $vistos[$codigo] = true;
                    if ($idioma === 'en') {
                        $sinTraduccion++;
                    }
                    $nombre = mb_strtoupper(preg_replace('/^(?:-\s*)+/u', '', $nombreOriginal), 'UTF-8');
                    $grupos = array_values(array_filter(array_map(fn ($columna) => trim($fila[$columna] ?? ''), ['N', 'O', 'P', 'Q', 'R']), fn ($valor) => $valor !== ''));
                    $lote[] = [
                        'codigo' => $codigo, 'nombre' => $nombre, 'nombre_original' => $nombre,
                        'nombre_ingles' => $nombreIngles !== '' ? mb_strtoupper($nombreIngles, 'UTF-8') : null, 'idioma_nombre' => $idioma, 'uri_fundacion' => trim($fila['A'] ?? '') ?: null,
                        'uri_linealizacion' => $uri, 'uri_padre' => trim($fila['T'] ?? '') ?: null,
                        'capitulo' => trim($fila['J'] ?? '') ?: null, 'nivel' => ($fila['H'] ?? '') === '' ? null : (int) $fila['H'],
                        'es_residual' => strtolower($fila['I'] ?? '') === 'true', 'es_hoja' => strtolower($fila['L'] ?? '') === 'true',
                        'tabulacion_principal' => ($fila['M'] ?? '') === '' ? null : strtolower($fila['M']) === 'true',
                        'agrupaciones' => $grupos ? json_encode($grupos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                        'nota_codificacion' => trim($fila['S'] ?? '') ?: null, 'version_fuente' => $version,
                        'created_at' => $momento, 'updated_at' => $momento,
                    ];
                    $contador++;
                    if (count($lote) >= 300) {
                        $this->guardarLote($lote);
                        $lote = [];
                    }
                }
                if (! $encabezado || ! $contador) {
                    throw new \RuntimeException('El archivo no contiene categorías codificadas.');
                }
                if ($lote) {
                    $this->guardarLote($lote);
                }
            });
            $lector->close();
            $this->info("Importadas o actualizadas: {$contador} categorías. Filas estructurales omitidas: {$omitidos}. {$version}");
            $this->info("Categorías sin traducción al español, conservadas en inglés: {$sinTraduccion}.");

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('La importación no se realizó: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $zip->close();
        }
    }

    private function guardarLote(array $lote): void
    {
        DB::table('diagnosticos')->upsert($lote, ['codigo'], ['nombre', 'nombre_original', 'nombre_ingles', 'idioma_nombre', 'uri_fundacion', 'uri_linealizacion', 'uri_padre', 'capitulo', 'nivel', 'es_residual', 'es_hoja', 'tabulacion_principal', 'agrupaciones', 'nota_codificacion', 'version_fuente', 'updated_at']);
    }
}
