<?php

namespace App\Flows\Pacientes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarPacienteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'min:3', 'max:25', Rule::unique('pacientes', 'ci')],
            'complemento_ci' => ['nullable', 'string', 'max:10'],
            'expedido_en' => ['nullable', 'string', 'max:50'],
            'nit' => ['nullable', 'string', 'max:25'],
            'razon_social' => ['nullable', 'string', 'max:180'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:today'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'genero' => ['required', 'string', 'max:30'],
            'ci_con_qr' => ['sometimes', 'boolean'],
            'afroamericano' => ['sometimes', 'boolean'],
            'pais' => ['required', 'string', 'max:80'],
            'departamento' => ['required', 'string', 'max:80'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'zona_barrio' => ['nullable', 'string', 'max:150'],
            'direccion_domicilio' => ['nullable', 'string', 'max:255'],
            'responsable_nombre_completo' => ['nullable', 'string', 'max:180'],
            'responsable_telefono' => ['nullable', 'string', 'max:30'],
            'responsable_parentesco' => ['nullable', 'string', 'max:80'],
            'como_se_entero' => ['required', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'habilitado' => ['sometimes', 'boolean'],
        ];
    }
}
