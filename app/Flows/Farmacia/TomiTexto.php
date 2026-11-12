<?php

namespace App\Flows\Farmacia;

final class TomiTexto
{
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/[^\p{L}\p{N}-]+/u', ' ', $texto) ?? $texto);
    }
}
