<?php

namespace App\Flows\Pacientes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PacienteResponsable extends Model
{
    use HasUuids;

    protected $table = 'paciente_responsables';

    protected $fillable = ['paciente_id', 'paciente_vinculado_id', 'nombre_completo', 'ci', 'telefono', 'parentesco', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function pacienteVinculado(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_vinculado_id');
    }
}
