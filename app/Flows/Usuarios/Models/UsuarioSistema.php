<?php

namespace App\Flows\Usuarios\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UsuarioSistema extends Model
{
    use HasUuids;

    protected $table = 'usuarios_sistema';

    protected $hidden = ['password'];

    protected $fillable = [
        'tipo_usuario',
        'paciente_id',
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'apellidos',
        'ci',
        'correo',
        'caja',
        'usuario',
        'password',
        'rol',
        'estado',
    ];
}
