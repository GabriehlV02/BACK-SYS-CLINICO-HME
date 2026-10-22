<?php

namespace App\Flows\Usuarios\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class UsuarioSistema extends Authenticatable
{
    use HasUuids;

    protected $connection = 'usuarios';

    protected $table = 'usuarios_sistema';

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['cajas' => 'array'];
    }

    protected $fillable = [
        'tipo_usuario',
        'paciente_id',
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'apellidos',
        'ci',
        'correo',
        'cajas',
        'usuario',
        'password',
        'rol',
        'estado',
    ];
}
