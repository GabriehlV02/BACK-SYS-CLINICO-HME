<?php

namespace App\Flows\Atenciones;

class PrioridadSignosVitales
{
    public static function fueraDeRango(string $valor, ?array $rango, ?int $edad): bool
    {
        if (! $rango || ! is_numeric($valor) || ! is_finite((float) $valor)) {
            return false;
        }
        $contexto = mb_strtolower($rango['contexto'] ?? '');
        if ($contexto !== '' && (! str_contains($contexto, 'adulto') || $edad === null || $edad < 20)) {
            return false;
        }

        return (float) $valor < $rango['minimo'] || (float) $valor > $rango['maximo'];
    }

    public static function resumir(?object $valoracion): ?array
    {
        if (! $valoracion || ! $valoracion->mediciones) {
            return null;
        }
        $mediciones = array_values(array_filter(json_decode($valoracion->mediciones, true, 512, JSON_THROW_ON_ERROR), fn (array $medicion): bool => ($medicion['alertaPrioritaria'] ?? false) === true));
        if (! $mediciones) {
            return null;
        }

        return ['id' => (string) $valoracion->id, 'registradoEn' => $valoracion->created_at, 'mediciones' => $mediciones];
    }
}
