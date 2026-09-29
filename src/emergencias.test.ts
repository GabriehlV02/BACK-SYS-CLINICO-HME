import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import { mkdtempSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { rutasEmergencias, importeConsumo } from './emergencias.js';

test('atención anónima, signos, consumos, identificación, persistencia y liberación', async () => {
  const temporal = mkdtempSync(join(tmpdir(), 'hme-emergencias-test-'));
  const archivo = join(temporal, 'cuentas.json');
  const catalogo = async () => [
    { id: 'ox', codigo: 'OX', nombre: 'Oxígeno', tipo: 'servicio' as const, unidad: 'TIEMPO', precio: 60 },
    { id: 'ga', codigo: 'GA', nombre: 'Gasa', tipo: 'producto' as const, unidad: 'UNITARIO', precio: 2.5 },
  ];
  const app = express(); app.use(express.json()); app.use('/emergencias', rutasEmergencias(archivo, catalogo));
  app.use('/reinicio', rutasEmergencias(archivo, catalogo));
  const server = app.listen(0, '127.0.0.1');
  await new Promise<void>(resolve => server.once('listening', resolve));
  const puerto = (server.address() as { port: number }).port;
  const llamar = async (ruta: string, body?: object, status = 200) => {
    const respuesta = await fetch(`http://127.0.0.1:${puerto}${ruta}`, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    const datos = await respuesta.json() as any; assert.equal(respuesta.status, status, JSON.stringify(datos)); return datos;
  };
  try {
    const inicial = await llamar('/emergencias', { cubiculo: '03', solicitudId: 'crear-1' }, 201);
    assert.equal(inicial.identidad, null); assert.equal(inicial.cubiculo, '3');
    assert.equal((await llamar('/emergencias', { cubiculo: '03', solicitudId: 'crear-1' })).id, inicial.id);
    await llamar('/emergencias', { cubiculo: '3', solicitudId: 'crear-2' }, 409);
    const ruta = `/emergencias/${inicial.id}`;
    await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 2.5, cantidad: 2, registroId: 'antes' }, 409);
    await llamar(`${ruta}/signos`, { valores: { saturacion: 101 } }, 400);
    await llamar(`${ruta}/signos`, { valores: {} }, 400);
    await llamar(`${ruta}/signos`, { valores: { saturacion: 97, temperatura: 36.5 }, observacion: '' });
    await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 2.5, cantidad: -1, registroId: 'negativo' }, 400);
    await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 2.5, cantidad: 1, porHora: true, registroId: 'producto-hora' }, 400);
    await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 1, cantidad: 2, registroId: 'precio' }, 409);
    await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 2.5, cantidad: 2, registroId: 'gasa-1' });
    const repetida = await llamar(`${ruta}/consumos`, { itemId: 'ga', precio: 2.5, cantidad: 2, registroId: 'gasa-1' });
    assert.equal(repetida.consumos.length, 1); assert.equal(repetida.total, 5);
    const ox = await llamar(`${ruta}/consumos`, { itemId: 'ox', precio: 60, porHora: true, registroId: 'ox-1' });
    const consumo = ox.consumos[1];
    assert.equal(importeConsumo(consumo, Date.parse(consumo.inicio) + 90 * 60000), 90);
    await llamar(`${ruta}/consumos`, { itemId: 'ox', precio: 60, porHora: true, registroId: 'ox-2' }, 409);
    await llamar(`${ruta}/finalizar`, {}, 409);
    await llamar(`${ruta}/identidad`, { revision: 0, nombres: 'Prueba', apellidos: 'Paciente' }, 409);
    const identificada = await llamar(`${ruta}/identidad`, { revision: ox.revision, nombres: 'Prueba', apellidos: 'Paciente', familiar: 'Acompañante', telefonoFamiliar: '123' });
    assert.equal(identificada.consumos.length, 2);
    const reinicio = await llamar('/reinicio'); assert.equal(reinicio.cuentas[0].identidad.nombres, 'Prueba');
    assert.equal(reinicio.cuentas[0].consumos[1].inicio, consumo.inicio);
    const final = await llamar(`${ruta}/finalizar-consumo`, { registroId: 'ox-1' });
    const final2 = await llamar(`${ruta}/finalizar-consumo`, { registroId: 'ox-1' });
    assert.equal(final.consumos[1].fin, final2.consumos[1].fin);
    assert.equal(importeConsumo(final.consumos[1], Date.now() + 86400000), importeConsumo(final.consumos[1]));
    await llamar(`${ruta}/finalizar`, {});
    await llamar(`${ruta}/signos`, { valores: { temperatura: 37 } }, 409);
    const nueva = await llamar('/emergencias', { cubiculo: '3', solicitudId: 'crear-3' }, 201);
    assert.notEqual(nueva.id, inicial.id); assert.equal(nueva.consumos.length, 0);
    assert.equal((await llamar('/reinicio')).cuentas.length, 2);
    await llamar('/emergencias', { solicitudId: 'sin-cubiculo', identidad: { nombres: 'Prueba' } }, 400);
    await llamar('/emergencias', { cubiculo: '4', solicitudId: 'fecha-invalida', identidad: { nacimiento: '2025-02-30' } }, 400);
    const parcial = await llamar('/emergencias', { cubiculo: '4', solicitudId: 'con-datos', identidad: { nombres: '  Prueba  ', telefono: '123' } }, 201);
    assert.equal(parcial.identidad.nombres, 'Prueba'); assert.equal(parcial.identidad.apellidos, '');
    assert.equal((await llamar('/reinicio')).cuentas.find((c: any) => c.id === parcial.id).identidad.telefono, '123');
    const sinDatos = await llamar('/emergencias', { cubiculo: '5', solicitudId: 'vacios', identidad: { nombres: '  ', documento: '' } }, 201);
    assert.equal(sinDatos.identidad, null);
  } finally {
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    // Solo el directorio temporal creado por esta prueba.
    rmSync(temporal, { recursive: true });
  }
});

test('médico y enfermería comparten notas, órdenes y aplicaciones sin cobros duplicados', async () => {
  const temporal = mkdtempSync(join(tmpdir(), 'hme-ordenes-test-')), archivo = join(temporal, 'cuentas.json');
  let catalogoDisponible = true;
  const catalogo = async () => {
    if (!catalogoDisponible) throw new Error('Sin conexión');
    return [
      { id: 'producto', codigo: 'P1', nombre: 'Producto de prueba', tipo: 'producto' as const, unidad: 'UNITARIO', precio: 10 },
      { id: 'servicio', codigo: 'S1', nombre: 'Servicio de prueba', tipo: 'servicio' as const, unidad: 'TIEMPO', precio: 60 },
    ];
  };
  const app = express(); app.use(express.json()); app.use('/api', rutasEmergencias(archivo, catalogo));
  const server = app.listen(0, '127.0.0.1'); await new Promise<void>(resolve => server.once('listening', resolve));
  const puerto = (server.address() as { port: number }).port;
  const llamar = async (ruta: string, body?: object, status = 200) => {
    const r = await fetch(`http://127.0.0.1:${puerto}/api${ruta}`, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    const datos = await r.json() as any; assert.equal(r.status, status, JSON.stringify(datos)); return datos;
  };
  try {
    let cuenta = await llamar('', { cubiculo: 'A', solicitudId: 'anonimo' }, 201);
    const base = `/${cuenta.id}`;
    // Simular una cuenta creada antes de incorporar atención médica.
    const legado = JSON.parse(readFileSync(archivo, 'utf8')); delete legado[0].evaluaciones; delete legado[0].ordenes;
    writeFileSync(archivo, JSON.stringify(legado));
    assert.deepEqual((await llamar('')).cuentas[0].ordenes, []);
    cuenta = await llamar(`${base}/evaluacion`, { revision: 0, campos: { estado: 'Paciente sin identificar; información pendiente' } });
    assert.equal(cuenta.identidad, null); assert.equal(cuenta.evaluaciones.length, 1);
    cuenta = await llamar(`${base}/evaluacion`, { revision: 1, campos: { estado: 'Información actualizada', informacion: 'Dato aportado por acompañante' } });
    assert.equal(cuenta.evaluaciones.length, 2); assert.match(cuenta.evaluaciones[0].campos.estado, /sin identificar/);
    await llamar(`${base}/evaluacion`, { revision: 1, campos: { estado: 'Edición antigua' } }, 409);
    cuenta = await llamar(`${base}/identidad`, { revision: cuenta.revision, familiar: 'Acompañante de prueba' });
    assert.equal(cuenta.identidad.nombres, ''); assert.equal(cuenta.identidad.familiar, 'Acompañante de prueba');
    const receta = { id: 'orden-1', revision: 0, tipo: 'medicamento', titulo: 'Prescripción de prueba', indicaciones: 'Instrucción registrada por el médico', dosis: 'Dosis escrita por el médico', via: 'Vía indicada', frecuencia: 'Según prescripción', itemId: 'producto', porHora: false };
    cuenta = await llamar(`${base}/orden`, receta);
    assert.equal(cuenta.consumos.length, 0); assert.equal(cuenta.total, 0);
    cuenta = await llamar(`${base}/orden`, receta); assert.equal(cuenta.ordenes.length, 1);
    const aplicacion = { ordenId: 'orden-1', revision: 1, registroId: 'aplicacion-1', itemId: 'producto', precio: 10, cantidad: 2 };
    await llamar(`${base}/aplicar-orden`, aplicacion, 409);
    await llamar(`${base}/signos`, { observacion: 'Medición pendiente durante atención inicial', valores: {} });
    cuenta = await llamar(`${base}/orden`, { ...receta, revision: 1, indicaciones: 'Indicación corregida antes de aplicar' });
    assert.equal(cuenta.ordenes[0].revision, 2);
    await llamar(`${base}/aplicar-orden`, aplicacion, 409);
    await llamar(`${base}/aplicar-orden`, { ...aplicacion, revision: 2, itemId: 'servicio', precio: 60 }, 400);
    cuenta = await llamar(`${base}/aplicar-orden`, { ...aplicacion, revision: 2 });
    assert.equal(cuenta.total, 20); assert.equal(cuenta.ordenes[0].estado, 'aplicada');
    assert.equal(cuenta.consumos[0].ordenId, 'orden-1');
    cuenta = await llamar(`${base}/aplicar-orden`, { ...aplicacion, revision: 2 });
    assert.equal(cuenta.consumos.length, 1); assert.equal(cuenta.ordenes[0].aplicaciones.length, 1);
    await llamar(`${base}/orden`, { ...receta, revision: 3 }, 409);
    cuenta = await llamar(`${base}/aplicar-orden`, { ...aplicacion, revision: 3, registroId: 'aplicacion-2', cantidad: 1 });
    assert.equal(cuenta.total, 30); assert.equal(cuenta.ordenes[0].aplicaciones.length, 2);
    await llamar(`${base}/cancelar-orden`, { ordenId: 'orden-1', revision: 4, motivo: 'Indicación suspendida por el médico' });
    await llamar(`${base}/aplicar-orden`, { ...aplicacion, revision: 5, registroId: 'aplicacion-3' }, 409);
    cuenta = await llamar(`${base}/orden`, { id: 'orden-2', revision: 0, tipo: 'servicio', titulo: 'Servicio por tiempo', indicaciones: 'Iniciar uso según indicación', itemId: 'servicio', porHora: true });
    assert.equal(cuenta.consumos.length, 2);
    cuenta = await llamar(`${base}/aplicar-orden`, { ordenId: 'orden-2', revision: 1, registroId: 'tiempo-1', itemId: 'servicio', precio: 60 });
    assert.equal(cuenta.ordenes[1].estado, 'en_curso'); assert.equal(cuenta.consumos[2].fin, null);
    await llamar(`${base}/cancelar-orden`, { ordenId: 'orden-2', revision: 2, motivo: 'Suspender' }, 409);
    await llamar(`${base}/finalizar`, {}, 409);
    cuenta = await llamar(`${base}/finalizar-consumo`, { registroId: 'tiempo-1' });
    assert.equal(cuenta.ordenes[1].estado, 'aplicada'); assert.ok(cuenta.consumos[2].fin);
    await llamar(`${base}/aplicar-orden`, { ordenId: 'orden-2', revision: 3, registroId: 'tiempo-2', itemId: 'servicio', precio: 60 });
    cuenta = await llamar(`${base}/finalizar-consumo`, { registroId: 'tiempo-1' });
    assert.equal(cuenta.ordenes[1].estado, 'en_curso');
    await llamar(`${base}/finalizar-consumo`, { registroId: 'tiempo-2' });
    // Las notas y órdenes no vinculadas se pueden registrar sin catálogo contable.
    catalogoDisponible = false;
    cuenta = await llamar(`${base}/orden`, { id: 'orden-3', revision: 0, tipo: 'cuidado', titulo: 'Cuidado general', indicaciones: 'Instrucción del médico' });
    cuenta = await llamar(`${base}/aplicar-orden`, { ordenId: 'orden-3', revision: 1, registroId: 'cuidado-1', observacion: 'Cumplimiento registrado por enfermería' });
    assert.equal(cuenta.consumos.length, 4); assert.equal(cuenta.ordenes[2].estado, 'aplicada');
    const guardada = (await llamar('')).cuentas[0];
    assert.equal(guardada.ordenes.length, 3); assert.equal(guardada.evaluaciones.length, 2);
    await llamar(`${base}/finalizar`, {});
    await llamar(`${base}/orden`, { id: 'orden-4', revision: 0, tipo: 'cuidado', titulo: 'Tardía', indicaciones: 'No aceptar' }, 409);
  } finally {
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    rmSync(temporal, { recursive: true });
  }
});
