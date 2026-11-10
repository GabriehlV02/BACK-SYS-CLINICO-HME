<?php

namespace App\Flows\Atenciones;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SincronizarOrdenVenta
{
    public function procesar(Connection $contable, string $ventaId): void
    {
        $pendientes = $contable->table('ventas_clinicas_ordenes as o')
            ->join('carrito_venta_detalles as d', 'd.id', '=', 'o.venta_detalle_id')
            ->join('carritos_venta as v', 'v.id', '=', 'd.carrito_venta_id')
            ->where('v.id', $ventaId)->where('v.estado', 'COMPLETADA')
            ->whereNull('o.procesada_en')->orderBy('o.id')->get(['o.*']);

        foreach ($pendientes as $orden) {
            if (! in_array($orden->tipo, ['RADIOGRAFIA', 'TOMOGRAFIA', 'ECOGRAFIA', 'ECOCARDIOGRAMA', 'LABORATORIO'], true)) {
                throw new RuntimeException('Tipo de estudio no admitido.');
            }
            DB::transaction(function () use ($orden): void {
                $ahora = now();
                DB::table('estudios')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'paciente_id' => $orden->paciente_id,
                    'codigo' => 'EST-'.strtoupper(substr(hash('sha256', $orden->venta_detalle_id.':'.$orden->unidad), 0, 32)),
                    'tipo' => $orden->tipo, 'nombre' => $orden->nombre, 'estado' => 'SOLICITADO',
                    'estado_detalle' => 'Cobrado en caja', 'solicitado_por' => 'Recepción', 'solicitado_en' => $ahora,
                    'venta_detalle_id' => $orden->venta_detalle_id, 'venta_detalle_unidad' => $orden->unidad,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $estudio = DB::table('estudios')->where('venta_detalle_id', $orden->venta_detalle_id)->where('venta_detalle_unidad', $orden->unidad)->first();
                if (! $estudio) {
                    throw new RuntimeException('No se pudo registrar el estudio.');
                }
                DB::table('estudios_'.strtolower($orden->tipo))->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'estudio_id' => $estudio->id,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }, 3);
            $contable->table('ventas_clinicas_ordenes')->where('id', $orden->id)->whereNull('procesada_en')->update(['procesada_en' => now(), 'updated_at' => now()]);
        }
    }
}
