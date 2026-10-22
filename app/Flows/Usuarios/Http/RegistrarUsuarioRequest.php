<?php

namespace App\Flows\Usuarios\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarUsuarioRequest extends FormRequest
{
    public function authorize(AccesoCompartido $acceso): bool
    {
        return in_array('USUARIOS_CREAR', $acceso->permisos($this->user()->id), true);
    }

    public function rules(): array
    {
        return [
            'tipo_usuario' => ['required', Rule::in(['EMPRESA'])],
            'paciente_id' => ['nullable', 'uuid', 'required_if:tipo_usuario,PACIENTE'],
            'nombres' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            'apellidos' => ['nullable', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:25'],
            'correo' => ['required', 'email', 'max:150'],
            'caja' => ['required', 'string', 'max:100'],
            'usuario' => ['required', 'string', 'max:60', Rule::unique('usuarios.usuarios_sistema', 'usuario')],
            'password' => ['required', 'string', 'min:8'],
            'rol' => ['required', 'uuid', Rule::exists('usuarios.roles', 'id')->where('activo', true)],
            'estado' => ['sometimes', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }
}
