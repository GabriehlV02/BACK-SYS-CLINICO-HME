<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsuarioAdministradorTemporalSeeder extends Seeder
{
    public function run(): void
    {
        $conexion = DB::connection('usuarios');
        $rolId = $conexion->table('roles')->where('codigo', 'ADMINISTRADOR')->value('id');
        $usuario = $conexion->table('usuarios_sistema')->where('usuario', 'admin')->first();
        $id = $usuario?->id ?? (string) Str::uuid();
        $conexion->table('usuarios_sistema')->updateOrInsert(['usuario' => 'admin'], ['id' => $id, 'tipo_usuario' => 'EMPRESA', 'nombres' => 'Administrador', 'apellidos' => 'temporal', 'correo' => 'admin@temporal.local', 'password' => Hash::make('admin'), 'rol' => 'Administrador', 'estado' => 'ACTIVO', 'updated_at' => now(), 'created_at' => $usuario?->created_at ?? now()]);
        $conexion->table('rol_usuario')->updateOrInsert(['usuario_id' => $id, 'rol_id' => $rolId]);
    }
}
