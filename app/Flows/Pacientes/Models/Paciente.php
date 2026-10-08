<?php

namespace App\Flows\Pacientes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paciente extends Model
{
    use HasUuids;

    protected $table = 'pacientes';

    protected $fillable = [
        'nombres', 'apellido_paterno', 'apellido_materno', 'ci', 'complemento_ci',
        'expedido_en', 'nit', 'razon_social', 'fecha_nacimiento', 'telefono', 'genero',
        'ci_con_qr', 'afroamericano', 'pais', 'departamento', 'ciudad', 'zona_barrio',
        'direccion_domicilio', 'responsable_nombre_completo', 'responsable_telefono',
        'responsable_parentesco',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'ci_con_qr' => 'boolean',
            'afroamericano' => 'boolean',
        ];
    }

    public function registrosAdmision(): HasMany
    {
        return $this->hasMany(RegistroAdmisionPaciente::class);
    }
}
