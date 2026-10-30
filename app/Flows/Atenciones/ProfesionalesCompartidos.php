<?php

namespace App\Flows\Atenciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfesionalesCompartidos
{
    public function obtener(): array
    {
        return DB::connection('usuarios')->table('profesionales')->where('activo', true)
            ->orderBy('apellidos')->orderBy('nombres')->get()->map(function ($profesional): array {
                $datos = (array) $profesional;
                $datos['especialidades'] = json_decode($profesional->especialidades, true, 512, JSON_THROW_ON_ERROR);
                $datos['nombre'] = trim($profesional->nombres.' '.$profesional->apellidos);
                $datos['activo'] = (bool) $profesional->activo;

                return $datos;
            })->all();
    }

    public function buscar(string $id, ?string $tipo = null): object
    {
        $profesional = DB::connection('usuarios')->table('profesionales')->where('id', $id)->where('activo', true)
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))->first();
        if (! $profesional) {
            throw ValidationException::withMessages(['profesional_id' => 'Selecciona un profesional activo del catálogo contable.']);
        }

        return $profesional;
    }
}
