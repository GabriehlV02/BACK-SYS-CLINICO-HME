<?php

namespace App\Flows\Pacientes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RegistroAdmisionPaciente extends Model
{
    use HasUuids;
    protected $table = 'registros_admision_paciente';
    protected $fillable = ['paciente_id', 'como_se_entero', 'observaciones', 'habilitado'];
    protected function casts(): array { return ['habilitado' => 'boolean']; }
}
