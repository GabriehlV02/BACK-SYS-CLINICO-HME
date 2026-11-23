<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $principal = $app['db']->connection();
        $compartida = $app['db']->connection('usuarios');

        foreach ([$principal, $compartida] as $conexion) {
            if ($conexion->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)(?:test|qa)(?:_|$)/', $conexion->getDatabaseName())) {
                throw new \RuntimeException('Las pruebas requieren bases PostgreSQL exclusivas de QA o test.');
            }
        }
        if ($principal->getDatabaseName() === $compartida->getDatabaseName()) {
            throw new \RuntimeException('Configura bases de pruebas distintas para clínico y contabilidad.');
        }

        return $app;
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
}
