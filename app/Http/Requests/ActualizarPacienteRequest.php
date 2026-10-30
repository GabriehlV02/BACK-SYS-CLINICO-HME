<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarPacienteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'min:3', 'max:25', Rule::unique('pacientes', 'ci')->ignore($this->route('paciente'))],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:today'],
            'genero' => ['required', 'in:Femenino,Masculino,Otro'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion_domicilio' => ['nullable', 'string', 'max:255'],
        ];
    }
}
