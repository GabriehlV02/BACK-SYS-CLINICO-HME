<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PermisosClinicosSeeder extends Seeder
{
    public function run(): void
    {
        $ahora = now();
        $permisos = [
            ['RECEPCION_VER','Ver Recepción','recepcion'], ['RECEPCION_CAJA_VER','Ver subvista Caja','recepcion'], ['RECEPCION_AGENDA_VER','Ver Agenda','recepcion'], ['RECEPCION_CITAS_GESTIONAR','Gestionar citas','recepcion'],
            ['PACIENTES_VER','Ver pacientes','pacientes'], ['PACIENTES_REGISTRAR','Registrar pacientes','pacientes'], ['PACIENTES_EDITAR','Editar pacientes','pacientes'], ['HISTORIAS_CLINICAS_VER','Ver historias clínicas','pacientes'],
            ['CONSULTORIO_VER','Ver Consultorio','consultorio'], ['CONSULTAS_ATENDER','Atender consultas','consultorio'], ['RECETAS_EMITIR','Emitir recetas','consultorio'],
            ['INTERNACION_VER','Ver Internación','internacion'], ['INTERNACION_GESTIONAR','Gestionar internación','internacion'], ['SIGNOS_VITALES_REGISTRAR','Registrar signos vitales','internacion'],
            ['IMAGENOLOGIA_VER','Ver Imagenología','estudios'], ['IMAGENOLOGIA_INFORMAR','Informar Imagenología','estudios'], ['LABORATORIO_VER','Ver Laboratorio','estudios'], ['LABORATORIO_INFORMAR','Informar Laboratorio','estudios'],
            ['USUARIOS_VER','Ver usuarios','administracion'], ['USUARIOS_CREAR','Registrar usuarios','administracion'], ['ROLES_GESTIONAR','Gestionar roles y permisos','administracion'], ['AUDITORIA_VER','Ver auditoría','administracion'],
        ];
        foreach ($permisos as [$codigo, $nombre, $modulo]) {
            DB::table('permisos')->updateOrInsert(['codigo' => $codigo], ['id' => DB::table('permisos')->where('codigo', $codigo)->value('id') ?? (string) Str::uuid(), 'nombre' => $nombre, 'modulo' => $modulo, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $rolId = DB::table('roles')->where('codigo', 'ADMINISTRADOR')->value('id') ?? (string) Str::uuid();
        DB::table('roles')->updateOrInsert(['codigo' => 'ADMINISTRADOR'], ['id' => $rolId, 'nombre' => 'Administrador', 'descripcion' => 'Acceso completo a todos los flujos y acciones del sistema clínico.', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach (DB::table('permisos')->whereIn('codigo', array_column($permisos, 0))->pluck('id') as $permisoId) {
            DB::table('permiso_rol')->updateOrInsert(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
        }

        $usuario = DB::table('usuarios_sistema')->where('usuario', 'admin')->first();
        $usuarioId = $usuario?->id ?? (string) Str::uuid();
        DB::table('usuarios_sistema')->updateOrInsert(['usuario' => 'admin'], ['id' => $usuarioId, 'tipo_usuario' => 'EMPRESA', 'nombres' => 'Administrador', 'apellidos' => 'del sistema', 'correo' => 'admin@hospital.local', 'password' => Hash::make('admin'), 'rol' => 'Administrador', 'estado' => 'ACTIVO', 'created_at' => $usuario?->created_at ?? $ahora, 'updated_at' => $ahora]);
        DB::table('rol_usuario')->updateOrInsert(['usuario_id' => $usuarioId, 'rol_id' => $rolId]);
    }
}
