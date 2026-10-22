<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PermisosClinicosSeeder extends Seeder
{
    public function run(): void
    {
        $db = DB::connection('usuarios');
        $sistema = $db->table('sistemas')->where('codigo', 'CLINICO')->value('id');
        $admin = $db->table('roles')->where('codigo', 'ADMINISTRADOR')->value('id');
        if (! $sistema || ! $admin) {
            throw new RuntimeException('Inicializa primero los sistemas y roles en la base del contable.');
        }
        $permisos = [
            ['RECEPCION_VER', 'Ver Recepción', 'recepcion'], ['RECEPCION_CAJA_VER', 'Ver subvista Caja', 'recepcion'], ['RECEPCION_AGENDA_VER', 'Ver Agenda', 'recepcion'], ['RECEPCION_CITAS_GESTIONAR', 'Gestionar citas', 'recepcion'],
            ['PACIENTES_VER', 'Ver pacientes', 'pacientes'], ['PACIENTES_REGISTRAR', 'Registrar pacientes', 'pacientes'], ['PACIENTES_EDITAR', 'Editar pacientes', 'pacientes'], ['HISTORIAS_CLINICAS_VER', 'Ver historias clínicas', 'pacientes'],
            ['CONSULTORIO_VER', 'Ver Consultorio', 'consultorio'], ['CONSULTAS_ATENDER', 'Atender consultas', 'consultorio'], ['RECETAS_EMITIR', 'Emitir recetas', 'consultorio'],
            ['INTERNACION_VER', 'Ver Internación', 'internacion'], ['INTERNACION_GESTIONAR', 'Gestionar internación', 'internacion'], ['SIGNOS_VITALES_REGISTRAR', 'Registrar signos vitales', 'internacion'],
            ['IMAGENOLOGIA_VER', 'Ver Imagenología', 'estudios'], ['IMAGENOLOGIA_INFORMAR', 'Informar Imagenología', 'estudios'], ['LABORATORIO_VER', 'Ver Laboratorio', 'estudios'], ['LABORATORIO_INFORMAR', 'Informar Laboratorio', 'estudios'],
            ['USUARIOS_VER', 'Ver usuarios', 'administracion'], ['USUARIOS_CREAR', 'Registrar usuarios', 'administracion'], ['ROLES_GESTIONAR', 'Gestionar roles y permisos', 'administracion'], ['AUDITORIA_VER', 'Ver auditoría', 'administracion'],
        ];
        $db->transaction(function () use ($db, $sistema, $admin, $permisos): void {
            foreach ($permisos as [$codigo, $nombre, $modulo]) {
                $db->table('permisos')->insertOrIgnore(['id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => $nombre, 'modulo' => $modulo, 'sistema_id' => $sistema, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
                $id = $db->table('permisos')->where('codigo', $codigo)->value('id');
                $db->table('permiso_rol')->updateOrInsert(['rol_id' => $admin, 'permiso_id' => $id]);
            }
            $db->table('rol_sistema')->updateOrInsert(['rol_id' => $admin, 'sistema_id' => $sistema]);
        });
    }
}
