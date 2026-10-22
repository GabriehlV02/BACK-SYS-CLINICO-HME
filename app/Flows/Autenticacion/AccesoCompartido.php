<?php

namespace App\Flows\Autenticacion;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AccesoCompartido
{
    public function conexion(): Connection
    {
        return DB::connection('usuarios');
    }

    public function roles(string $usuarioId): Builder
    {
        return $this->conexion()->table('roles as r')->join('rol_usuario as ru', 'ru.rol_id', '=', 'r.id')
            ->where('ru.usuario_id', $usuarioId)->where('r.activo', true)
            ->whereExists(function (Builder $q): void {
                $q->selectRaw('1')->from('rol_sistema as rs')->join('sistemas as s', 's.id', '=', 'rs.sistema_id')
                    ->whereColumn('rs.rol_id', 'r.id')->whereIn('s.codigo', ['CLINICO', 'ESTUDIOS'])->where('s.activo', true);
            });
    }

    public function permitido(object $usuario): bool
    {
        return $usuario->estado === 'ACTIVO' && $usuario->tipo_usuario === 'EMPRESA' && $this->roles($usuario->id)->exists();
    }

    public function permisos(string $usuarioId): array
    {
        return $this->conexion()->table('permisos as p')->join('permiso_rol as pr', 'pr.permiso_id', '=', 'p.id')
            ->join('sistemas as s', 's.id', '=', 'p.sistema_id')
            ->whereIn('pr.rol_id', $this->roles($usuarioId)->select('r.id'))
            ->where('p.activo', true)->where('s.activo', true)->distinct()->orderBy('p.codigo')->pluck('p.codigo')->all();
    }

    public function datos(object $usuario): array
    {
        return ['id' => $usuario->id, 'usuario' => $usuario->usuario, 'nombre' => trim($usuario->nombres.' '.($usuario->apellidos ?? '')),
            'apellidos' => $usuario->apellidos, 'correo' => $usuario->correo, 'ci' => $usuario->ci,
            'rol' => $this->roles($usuario->id)->orderBy('r.nombre')->pluck('r.nombre')->implode(', '),
            'permisos' => array_map(fn (string $codigo): string => strtolower(str_replace('_', '.', $codigo)), $this->permisos($usuario->id)),
            'tipoInicio' => 'admin_sistema'];
    }

    public function emitir(object $usuario): array
    {
        $expira = now()->addHours(8)->getTimestamp();
        $token = Crypt::encryptString(json_encode(['sub' => $usuario->id, 'exp' => $expira, 'huella' => hash('sha256', $usuario->password), 'nonce' => Str::random(32)], JSON_THROW_ON_ERROR));

        return ['token' => $token, 'expiresAt' => $expira * 1000, 'usuario' => $this->datos($usuario)];
    }

    public function verificar(?string $token): object
    {
        abort_unless($token && strlen($token) <= 4096, 401, 'Inicia sesión para continuar.');
        try {
            $datos = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(401, 'La sesión no es válida.');
        }
        abort_unless(is_array($datos) && isset($datos['sub'], $datos['exp'], $datos['huella']) && is_string($datos['sub']) && Str::isUuid($datos['sub']) && is_string($datos['huella']) && is_int($datos['exp']) && $datos['exp'] > now()->getTimestamp(), 401, 'La sesión expiró.');
        abort_if(Cache::has('sesion-clinica-revocada:'.hash('sha256', $token)), 401, 'La sesión fue cerrada.');
        $usuario = $this->conexion()->table('usuarios_sistema')->where('id', $datos['sub'])->first();
        abort_unless($usuario && $this->permitido($usuario) && hash_equals(hash('sha256', $usuario->password), $datos['huella']), 401, 'Tu acceso cambió. Inicia sesión nuevamente.');

        return $usuario;
    }

    public function revocar(string $token): void
    {
        Cache::put('sesion-clinica-revocada:'.hash('sha256', $token), true, now()->addHours(8));
    }

    public function exigir(string $usuarioId, string $permiso): void
    {
        abort_unless(in_array($permiso, $this->permisos($usuarioId), true), 403, 'No tienes permiso para realizar esta acción.');
    }
}
