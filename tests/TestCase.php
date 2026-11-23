<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    private ?string $esquemaUsuariosDePrueba = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $principal = $app['db']->connection();
        $compartida = $app['db']->connection('usuarios');

        if ($principal->getDriverName() !== 'sqlite' || $principal->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Las pruebas requieren SQLite en memoria para la base clínica.');
        }
        if ($compartida->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)(?:test|qa)(?:_|$)/', $compartida->getDatabaseName())) {
            throw new \RuntimeException('Las pruebas requieren una base PostgreSQL exclusiva de QA o test para usuarios compartidos.');
        }

        $this->esquemaUsuariosDePrueba = 'test_'.bin2hex(random_bytes(8));
        $compartida->statement('CREATE SCHEMA "'.$this->esquemaUsuariosDePrueba.'"');
        $app['config']->set('database.connections.usuarios.search_path', $this->esquemaUsuariosDePrueba);
        $app['db']->purge('usuarios');
        $app['db']->connection('usuarios');

        Schema::connection('usuarios')->create('clinico_solicitudes_insumos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });

        RefreshDatabaseState::$migrated = false;

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->eliminarEsquemaUsuariosDePrueba());
    }

    protected function aislarUsuarios(): void
    {
        $db = DB::connection('usuarios');
        $db->beginTransaction();
        $esquema = 'fixture_'.bin2hex(random_bytes(8));
        $db->statement('CREATE SCHEMA "'.$esquema.'"');
        $db->statement('SET LOCAL search_path TO "'.$esquema.'"');
        $this->beforeApplicationDestroyed(fn () => DB::purge('usuarios'));
    }

    private function eliminarEsquemaUsuariosDePrueba(): void
    {
        if (! $this->app || ! $this->esquemaUsuariosDePrueba) {
            return;
        }

        $conexion = $this->app['db']->connection('usuarios');
        while ($conexion->transactionLevel() > 0) {
            $conexion->rollBack();
        }
        $conexion->statement('SET search_path TO public');
        $conexion->statement('DROP SCHEMA IF EXISTS "'.$this->esquemaUsuariosDePrueba.'" CASCADE');
        $this->esquemaUsuariosDePrueba = null;
    }
}
