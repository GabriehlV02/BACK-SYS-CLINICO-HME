<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class ImportarDiagnosticosCie11Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('diagnosticos');
        (require database_path('migrations/2026_11_06_201829_create_diagnosticos_table.php'))->up();
        (require database_path('migrations/2026_11_06_202402_add_idioma_nombre_to_diagnosticos_table.php'))->up();
    }

    public function test_importa_categorias_y_no_duplica_al_repetir(): void
    {
        $archivo = $this->crearArchivo([
            ['B' => 'uri:grupo', 'F' => 'Grupo', 'G' => 'block'],
            ['A' => 'uri:fundacion', 'B' => 'uri:linealizacion', 'C' => '1A00', 'E' => 'Cholera', 'F' => '- - Cólera', 'G' => 'category', 'H' => '1', 'I' => 'False', 'J' => '01', 'L' => 'True', 'M' => 'True', 'N' => 'grupo', 'S' => 'Nota de prueba', 'T' => 'uri:padre'],
            ['B' => 'uri:ingles', 'C' => 'SK01', 'E' => '- - Migraine disorder (TM2)', 'G' => 'category'],
        ]);
        try {
            $this->artisan('diagnosticos:importar-cie11', ['archivo' => $archivo])->assertSuccessful();
            $this->artisan('diagnosticos:importar-cie11', ['archivo' => $archivo])->assertSuccessful();
            $this->assertDatabaseCount('diagnosticos', 2);
            $this->assertDatabaseHas('diagnosticos', ['codigo' => '1A00', 'idioma_nombre' => 'es', 'version_fuente' => 'Version: prueba']);
            $this->assertDatabaseHas('diagnosticos', ['codigo' => 'SK01', 'nombre' => 'MIGRAINE DISORDER (TM2)', 'idioma_nombre' => 'en']);
            $this->assertDatabaseHas('diagnosticos', ['codigo' => '1A00', 'nombre' => 'CÓLERA', 'nombre_original' => 'CÓLERA', 'nombre_ingles' => 'CHOLERA', 'es_hoja' => true, 'capitulo' => '01', 'nota_codificacion' => 'Nota de prueba', 'uri_padre' => 'uri:padre']);
        } finally {
            unlink($archivo);
        }
    }

    public function test_archivo_duplicado_revierte_incluso_lotes_ya_procesados(): void
    {
        $filas = [];
        for ($i = 0; $i < 301; $i++) {
            $filas[] = ['B' => 'uri:'.$i, 'C' => 'COD'.$i, 'F' => 'Diagnóstico '.$i, 'G' => 'category'];
        }
        $filas[] = $filas[0];
        $archivo = $this->crearArchivo($filas);
        try {
            $this->artisan('diagnosticos:importar-cie11', ['archivo' => $archivo])->assertFailed();
            $this->assertSame(0, DB::table('diagnosticos')->count());
        } finally {
            unlink($archivo);
        }
    }

    private function crearArchivo(array $filas): string
    {
        $cabecera = ['A' => 'Foundation URI', 'B' => 'Linearization URI', 'C' => 'Code', 'F' => 'Title', 'G' => 'ClassKind', 'T' => 'Parent', 'U' => 'Version: prueba'];
        $textos = [];
        $xmlFilas = '';
        foreach ([$cabecera, ...$filas] as $i => $fila) {
            $xmlFilas .= '<row r="'.($i + 1).'">';
            foreach ($fila as $columna => $valor) {
                $indice = count($textos);
                $textos[] = '<si><t>'.htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
                $xmlFilas .= '<c r="'.$columna.($i + 1).'" t="s"><v>'.$indice.'</v></c>';
            }
            $xmlFilas .= '</row>';
        }
        $archivo = tempnam(sys_get_temp_dir(), 'cie11-');
        $zip = new ZipArchive;
        $zip->open($archivo, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', $textos).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlFilas.'</sheetData></worksheet>');
        $zip->close();

        return $archivo;
    }
}
