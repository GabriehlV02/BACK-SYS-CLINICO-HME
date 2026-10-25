<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsuarioAdministradorPruebaSeeder extends Seeder
{
    public function run(): void
    {
        $conexion = DB::connection('usuarios');
        $rolId = $conexion->table('roles')->where('codigo', 'ADMINISTRADOR')->value('id');
        if (! $rolId) {
            throw new \RuntimeException('No existe el rol ADMINISTRADOR en la base compartida.');
        }

        $existente = $conexion->table('usuarios_sistema')->where('usuario', 'gabriel')->first();
        $id = $existente?->id ?? (string) Str::uuid();
        $conexion->transaction(function () use ($conexion, $existente, $id, $rolId): void {
            $conexion->table('usuarios_sistema')->updateOrInsert(['usuario' => 'gabriel'], [
                'id' => $id,
                'tipo_usuario' => 'EMPRESA',
                'nombres' => 'Gabriel',
                'apellidos' => 'Veramendi',
                'correo' => 'gabrielveramendi02@gmail.com',
                'password' => Hash::make('123456'),
                'rol' => 'Administrador',
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => $existente?->created_at ?? now(),
            ]);
            $conexion->table('rol_usuario')->updateOrInsert(['usuario_id' => $id, 'rol_id' => $rolId]);
        });
    }
}
