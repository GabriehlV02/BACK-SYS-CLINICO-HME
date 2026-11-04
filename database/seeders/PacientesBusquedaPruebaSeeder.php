<?php

namespace Database\Seeders;

use App\Flows\Pacientes\Models\Paciente;
use App\Flows\Pacientes\Models\RegistroAdmisionPaciente;
use Illuminate\Database\Seeder;

class PacientesBusquedaPruebaSeeder extends Seeder
{
    public function run(): void
    {
        $pacientes = [
            ['Luis Armando', 'Rojas', 'Vallejos', '78120431'], ['Luis Esteban', 'Escobar', 'Rocha', '68291547'],
            ['Luis Fernando', 'Nava', 'Mamani', '79463128'], ['Luis', 'Fernandez', 'Choque', '61847295'],
            ['Luis', 'Choque', 'Choque', '73581964'], ['Luis Alberto', 'Mendoza', 'Vargas', '84621753'],
            ['Luis Carlos', 'Quispe', 'Ramos', '69350418'], ['Luis Daniel', 'Flores', 'Molina', '75216839'],
            ['Luis Enrique', 'Torrez', 'Paredes', '80934126'], ['Luis Felipe', 'Castro', 'Arias', '67192584'],
            ['Luis Gabriel', 'Soto', 'Luna', '78365219'], ['Luis Hector', 'Copa', 'Villacorta', '69481735'],
            ['Luis Ivan', 'Condori', 'Huanca', '81520647'], ['Luis Javier', 'Rivera', 'Salas', '72648193'],
            ['Luis Manuel', 'Paz', 'Cruz', '89317462'], ['Luis Miguel', 'Rojas', 'Arce', '64592817'],
            ['Luis Oscar', 'Vargas', 'Siles', '77430561'], ['Luis Pablo', 'Mamani', 'Guzman', '82916473'],
            ['Luis Ricardo', 'Lopez', 'Calle', '65831724'], ['Luis Sergio', 'Aguirre', 'Medina', '91240568'],
        ];

        foreach ($pacientes as [$nombres, $paterno, $materno, $ci]) {
            $paciente = Paciente::query()->updateOrCreate(['ci' => $ci], [
                'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'fecha_nacimiento' => '1990-01-15', 'genero' => 'Masculino', 'pais' => 'Bolivia',
                'departamento' => 'Cochabamba', 'ciudad' => 'Cochabamba', 'telefono' => '',
            ]);
            RegistroAdmisionPaciente::query()->updateOrCreate(['paciente_id' => $paciente->id], [
                'como_se_entero' => 'Otro', 'observaciones' => 'Paciente de prueba para buscador', 'habilitado' => true,
            ]);
        }
    }
}
