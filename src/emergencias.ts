import { Router } from 'express';
import { randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

type Item = { id: string; codigo: string; nombre: string; tipo: 'servicio' | 'producto'; unidad: string; precio: number };
type Consumo = Item & { registroId: string; cantidad: number; porHora: boolean; inicio: string; fin: string | null; usuario: string; ordenId?: string };
type Signos = { id: string; fecha: string; usuario: string; valores: Record<string, number>; observacion: string };
type Identidad = { nombres: string; apellidos: string; documento: string; nacimiento: string; telefono: string; direccion: string; familiar: string; parentesco: string; telefonoFamiliar: string; documentoFamiliar: string };
type Evaluacion = { revision: number; fecha: string; usuario: string; campos: Record<string, string> };
type Aplicacion = { id: string; fecha: string; usuario: string; observacion: string; consumoId?: string };
type Orden = { id: string; revision: number; fecha: string; usuario: string; tipo: string; titulo: string; indicaciones: string; dosis: string; via: string; frecuencia: string; duracion: string; itemId: string; porHora: boolean; estado: 'pendiente' | 'en_curso' | 'aplicada' | 'cancelada'; aplicaciones: Aplicacion[]; historial: { fecha: string; usuario: string; detalle: string }[] };
type Cuenta = { id: string; solicitudId: string; cubiculo: string; inicio: string; fin: string | null; creadoPor: string; revision: number; identidad: Identidad | null; signos: Signos[]; consumos: Consumo[]; evaluaciones: Evaluacion[]; ordenes: Orden[] };
const limpiar = (v: unknown, max = 200) => typeof v === 'string' ? v.trim().slice(0, max) : '';
function exigir(ok: unknown, message: string, status = 400): asserts ok { if (!ok) throw Object.assign(new Error(message), { status }); }
function leerIdentidad(datos: Record<string, unknown> = {}): Identidad | null {
  const identidad = Object.fromEntries(['nombres', 'apellidos', 'documento', 'nacimiento', 'telefono', 'direccion', 'familiar', 'parentesco', 'telefonoFamiliar', 'documentoFamiliar'].map(k => [k, limpiar(datos?.[k])])) as Identidad;
  const nacimiento = identidad.nacimiento;
  exigir(!nacimiento || (/^\d{4}-\d{2}-\d{2}$/.test(nacimiento) && Number.isFinite(Date.parse(nacimiento)) && new Date(nacimiento).toISOString().slice(0, 10) === nacimiento && nacimiento <= new Date().toISOString().slice(0, 10)), 'Fecha de nacimiento inválida.');
  return Object.values(identidad).some(Boolean) ? identidad : null;
}
export const importeConsumo = (c: Consumo, ahora = Date.now()) => Math.round(c.precio * (c.porHora ? Math.max(0, (c.fin ? Date.parse(c.fin) : ahora) - Date.parse(c.inicio)) / 3_600_000 : c.cantidad) * 100) / 100;

export async function catalogoContable(): Promise<Item[]> {
  const respuesta = await fetch(`${process.env.CONTABLE_API_URL || 'http://127.0.0.1:5055'}/api/inventario`, { signal: AbortSignal.timeout(8000) });
  exigir(respuesta.ok, 'No se pudo consultar el catálogo contable.', 503);
  const datos = await respuesta.json() as { data: Record<string, unknown>[] };
  exigir(Array.isArray(datos.data), 'El catálogo contable no es válido.', 503);
  return datos.data.filter(i => String(i.estado).toUpperCase() === 'ACTIVO' && !i.revisionPrecio && Number.isFinite(i.precioVenta) && Number(i.precioVenta) >= 0).map(i => ({
    id: String(i.id), codigo: String(i.codigo), nombre: String(i.nombre), tipo: String(i.tipo).toUpperCase() === 'SERVICIO' ? 'servicio' : 'producto', unidad: String(i.unidadMedida), precio: Number(i.precioVenta),
  }));
}

export function rutasEmergencias(archivo: string, catalogo = catalogoContable) {
  const router = Router();
  const leer = (): Cuenta[] => existsSync(archivo) ? JSON.parse(readFileSync(archivo, 'utf8')).map((c: Cuenta) => ({ ...c, evaluaciones: c.evaluaciones || [], ordenes: c.ordenes || [] })) : [];
  const guardar = (cuentas: Cuenta[]) => { mkdirSync(dirname(archivo), { recursive: true }); writeFileSync(`${archivo}.tmp`, JSON.stringify(cuentas, null, 2)); renameSync(`${archivo}.tmp`, archivo); };
  const presentar = (c: Cuenta) => ({ ...c, total: Math.round(c.consumos.reduce((s, i) => s + importeConsumo(i), 0) * 100) / 100 });
  router.get('/', (_req, res) => res.json({ cuentas: leer().map(presentar), ahora: new Date().toISOString() }));
  router.get('/catalogo', (_req, res, next) => { void catalogo().then(items => res.json(items)).catch(() => next(Object.assign(new Error('Catálogo contable no disponible. Reintenta cuando Contabilidad esté conectada.'), { status: 503 }))); });
  router.post('/', (req, res) => {
    const cuentas = leer(), cubiculo = limpiar(req.body?.cubiculo, 12).toUpperCase().replace(/^0+(?=\d)/, ''), solicitudId = limpiar(req.body?.solicitudId, 80);
    exigir(/^[A-Z0-9-]{1,12}$/.test(cubiculo), 'Indica el número o código del cubículo.');
    exigir(solicitudId, 'Falta el identificador de solicitud.');
    const anterior = cuentas.find(c => c.solicitudId === solicitudId);
    if (anterior) return res.json(presentar(anterior));
    exigir(!cuentas.some(c => !c.fin && c.cubiculo === cubiculo), 'Este cubículo ya tiene una atención activa.', 409);
    const cuenta: Cuenta = { id: randomUUID(), solicitudId, cubiculo, inicio: new Date().toISOString(), fin: null, creadoPor: (req as any).auth?.userId || '', revision: 0, identidad: leerIdentidad(req.body?.identidad), signos: [], consumos: [], evaluaciones: [], ordenes: [] };
    cuentas.unshift(cuenta); guardar(cuentas); res.status(201).json(presentar(cuenta));
  });
  router.post('/:id/:accion', (req, res, next) => { void (async () => {
    const accion = String(req.params.accion);
    // Consultar precios antes de leer las cuentas evita sobrescribir otras operaciones durante la espera.
    const items = accion === 'consumos' || (['orden', 'aplicar-orden'].includes(accion) && req.body?.itemId) ? await catalogo() : [];
    const cuentas = leer(), cuenta = cuentas.find(c => c.id === String(req.params.id));
    exigir(cuenta, 'Cuenta no encontrada.', 404);
    const body = req.body || {}, ahora = new Date().toISOString(), usuario = (req as any).auth?.userId || '';
    if (accion === 'identidad') {
      exigir(body.revision === cuenta.revision, 'La cuenta cambió. Actualiza y vuelve a guardar los datos.', 409);
      const identidad = leerIdentidad(body);
      exigir(identidad, 'Anota al menos un dato conocido del paciente o acompañante.');
      cuenta.identidad = identidad;
    } else {
      exigir(!cuenta.fin, 'La atención ya finalizó.', 409);
      if (accion === 'evaluacion') {
        const anterior = cuenta.evaluaciones.at(-1);
        exigir(body.revision === (anterior?.revision || 0), 'La evaluación fue actualizada por otro usuario. Recarga la última versión antes de guardar.', 409);
        const campos = Object.fromEntries(['motivo', 'estado', 'antecedentes', 'alergias', 'evaluacion', 'diagnostico', 'plan', 'informacion'].map(k => [k, limpiar(body.campos?.[k], 5000)]));
        exigir(Object.values(campos).some(Boolean), 'Anota al menos un dato de la atención.');
        cuenta.evaluaciones.push({ revision: (anterior?.revision || 0) + 1, fecha: ahora, usuario, campos });
      } else if (accion === 'orden') {
        const id = limpiar(body.id, 80), anterior = cuenta.ordenes.find(o => o.id === id);
        exigir(id, 'Falta el identificador de la indicación.');
        if (anterior && body.revision === 0) return res.json(presentar(cuenta));
        exigir(!anterior || (anterior.revision === body.revision && anterior.estado === 'pendiente'), 'La indicación cambió o ya fue aplicada. Actualiza la cuenta.', 409);
        const tipo = limpiar(body.tipo), titulo = limpiar(body.titulo), indicaciones = limpiar(body.indicaciones, 3000), itemId = limpiar(body.itemId, 80), porHora = body.porHora === true;
        exigir(['medicamento', 'servicio', 'laboratorio', 'imagenologia', 'internacion', 'cuidado'].includes(tipo) && titulo && indicaciones, 'Completa tipo, nombre e indicaciones de la orden.');
        const item = items.find(i => i.id === itemId);
        exigir(!itemId || item, 'El ítem seleccionado no está disponible en el catálogo.');
        exigir(!porHora || !item || item.tipo === 'servicio', 'Solo un servicio puede tener contador de tiempo.');
        const orden: Orden = { id, revision: (anterior?.revision || 0) + 1, fecha: anterior?.fecha || ahora, usuario, tipo, titulo, indicaciones, dosis: limpiar(body.dosis), via: limpiar(body.via), frecuencia: limpiar(body.frecuencia), duracion: limpiar(body.duracion), itemId, porHora, estado: 'pendiente', aplicaciones: [], historial: [...(anterior?.historial || []), { fecha: ahora, usuario, detalle: anterior ? `Versión anterior: ${JSON.stringify({ ...anterior, historial: undefined, aplicaciones: undefined })}` : 'Indicación emitida' }] };
        if (anterior) cuenta.ordenes[cuenta.ordenes.indexOf(anterior)] = orden; else cuenta.ordenes.push(orden);
      } else if (accion === 'cancelar-orden') {
        const orden = cuenta.ordenes.find(o => o.id === body.ordenId);
        exigir(orden, 'Indicación no encontrada.', 404);
        exigir(orden.revision === body.revision, 'La indicación cambió. Actualiza la cuenta.', 409);
        exigir(orden.estado !== 'en_curso', 'Finaliza primero el servicio que está en uso.', 409);
        const motivo = limpiar(body.motivo, 1000); exigir(motivo, 'Anota el motivo de la suspensión.');
        orden.estado = 'cancelada'; orden.revision++;
        orden.historial.push({ fecha: ahora, usuario, detalle: `Suspendida: ${motivo}` });
      } else if (accion === 'aplicar-orden') {
        const orden = cuenta.ordenes.find(o => o.id === body.ordenId), registroId = limpiar(body.registroId, 80);
        exigir(orden, 'Indicación no encontrada.', 404); exigir(registroId, 'Falta el identificador de aplicación.');
        if (orden.aplicaciones.some(a => a.id === registroId)) return res.json(presentar(cuenta));
        exigir(!cuenta.consumos.some(c => c.registroId === registroId), 'El identificador de aplicación ya pertenece a otro consumo.', 409);
        exigir(orden.revision === body.revision, 'La indicación cambió. Revisa la versión actual antes de aplicarla.', 409);
        exigir(orden.estado !== 'cancelada' && orden.estado !== 'en_curso', 'Esta indicación está suspendida o tiene un servicio en curso.', 409);
        exigir(cuenta.signos.length, 'Registra primero la valoración de signos vitales.', 409);
        const itemId = limpiar(body.itemId, 80), observacion = limpiar(body.observacion, 1000);
        exigir(!orden.itemId || orden.itemId === itemId, 'Utiliza el ítem indicado por el médico.');
        exigir(itemId || !['medicamento', 'servicio'].includes(orden.tipo), 'Selecciona el producto o servicio utilizado para registrar su consumo.');
        if (itemId) {
          const item = items.find(i => i.id === itemId); exigir(item, 'El ítem no está disponible en el catálogo.');
          exigir(item.precio === body.precio, 'La tarifa cambió. Actualiza el catálogo.', 409);
          exigir(!orden.porHora || item.tipo === 'servicio', 'El contador requiere un servicio.');
          const cantidad = orden.porHora ? 1 : body.cantidad;
          exigir(typeof cantidad === 'number' && Number.isFinite(cantidad) && cantidad > 0 && cantidad <= 100000, 'Indica la cantidad realmente utilizada.');
          exigir(!orden.porHora || !cuenta.consumos.some(c => c.id === itemId && c.porHora && !c.fin), 'Este servicio ya está en curso.', 409);
          cuenta.consumos.push({ ...item, registroId, ordenId: orden.id, cantidad, porHora: orden.porHora, inicio: ahora, fin: orden.porHora ? null : ahora, usuario });
        } else exigir(observacion && !orden.porHora, 'Anota el cumplimiento de la indicación o selecciona el servicio utilizado.');
        orden.aplicaciones.push({ id: registroId, fecha: ahora, usuario, observacion, ...(itemId ? { consumoId: registroId } : {}) });
        orden.estado = orden.porHora ? 'en_curso' : 'aplicada'; orden.revision++;
      } else if (accion === 'signos') {
        const valores: Record<string, number> = {};
        for (const campo of ['temperatura', 'frecuenciaCardiaca', 'frecuenciaRespiratoria', 'presionSistolica', 'presionDiastolica', 'saturacion', 'glicemia', 'peso']) {
          const valor = body.valores?.[campo];
          if (valor === undefined || valor === '') continue;
          exigir(typeof valor === 'number' && Number.isFinite(valor) && valor >= 0 && (campo !== 'saturacion' || valor <= 100), 'Los signos vitales deben ser números válidos.');
          valores[campo] = valor;
        }
        const observacion = limpiar(body.observacion, 1000);
        exigir(Object.keys(valores).length || observacion, 'Registra signos vitales o el motivo por el que no se pudieron medir.');
        cuenta.signos.push({ id: randomUUID(), fecha: ahora, usuario, valores, observacion });
      } else if (accion === 'consumos') {
        const registroId = limpiar(body.registroId, 80);
        exigir(registroId, 'Falta el identificador de consumo.');
        if (cuenta.consumos.some(c => c.registroId === registroId)) return res.json(presentar(cuenta));
        exigir(cuenta.signos.length, 'Registra primero la valoración de signos vitales.', 409);
        const item = items.find(i => i.id === body.itemId);
        exigir(item, 'El ítem no está disponible en el catálogo.');
        exigir(body.precio === item.precio, 'La tarifa cambió. Actualiza el catálogo antes de registrar.', 409);
        const porHora = body.porHora === true, cantidad = porHora ? 1 : body.cantidad;
        exigir(!porHora || item.tipo === 'servicio', 'Solo los servicios pueden cobrarse por hora.');
        exigir(typeof cantidad === 'number' && Number.isFinite(cantidad) && cantidad > 0 && cantidad <= 100000, 'La cantidad debe ser mayor que cero.');
        exigir(!porHora || !cuenta.consumos.some(c => c.id === item.id && c.porHora && !c.fin), 'Este servicio ya está en curso.', 409);
        cuenta.consumos.push({ ...item, registroId, cantidad, porHora, inicio: ahora, fin: porHora ? null : ahora, usuario });
      } else if (accion === 'finalizar-consumo') {
        const consumo = cuenta.consumos.find(c => c.registroId === body.registroId);
        exigir(consumo?.porHora, 'Servicio por tiempo no encontrado.', 404);
        const estabaEnCurso = !consumo.fin;
        consumo.fin ||= ahora;
        const orden = cuenta.ordenes.find(o => o.id === consumo.ordenId);
        if (estabaEnCurso && orden?.estado === 'en_curso') { orden.estado = 'aplicada'; orden.revision++; orden.historial.push({ fecha: ahora, usuario, detalle: 'Servicio finalizado por enfermería' }); }
      } else if (accion === 'finalizar') {
        exigir(!cuenta.consumos.some(c => c.porHora && !c.fin), 'Finaliza los servicios en curso antes de liberar el cubículo.', 409);
        exigir(cuenta.signos.length, 'Registra la valoración inicial antes de finalizar.', 409);
        cuenta.fin = ahora;
      } else exigir(false, 'Operación desconocida.', 404);
    }
    cuenta.revision++; guardar(cuentas); res.json(presentar(cuenta));
  })().catch(next); });
  router.use((error: any, _req: any, res: any, _next: any) => res.status(error.status || 500).json({ message: error.status ? error.message : 'No se pudo guardar la operación. Reintenta.' }));
  return router;
}
