'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
require('../public/assets/ui.js');
const { searchable, paginate, periodPreset, filterPayrolls } = globalThis.NominaUI;

test('La búsqueda encuentra nombres con acentos y mayúsculas', () => {
  assert.ok(searchable('José Muñoz · Administración').includes(searchable('  JOSE munoz  ')));
  assert.equal(searchable(null), '');
});

test('La paginación no pierde ni duplica registros entre páginas', () => {
  const rows = Array.from({ length: 23 }, (_, index) => ({ id: index + 1 }));
  const pages = [1, 2, 3].map(page => paginate(rows, page));
  assert.deepEqual(pages.flatMap(page => page.items), rows);
  assert.deepEqual(pages.map(({ start, end }) => [start, end]), [[1, 10], [11, 20], [21, 23]]);
  assert.equal(paginate(rows, 8).page, 3);
});

test('La paginación se reajusta cuando el filtro reduce resultados', () => {
  assert.equal(paginate([{ id: 1 }], 4).page, 1);
  assert.deepEqual(paginate([], 4), { items: [], page: 1, pages: 1, total: 0, start: 0, end: 0 });
});

test('Las quincenas respetan febrero y los años bisiestos', () => {
  assert.deepEqual(periodPreset('2024-02-06', 'second'), { start: '2024-02-16', end: '2024-02-29' });
  assert.deepEqual(periodPreset('2026-02-19', 'second'), { start: '2026-02-16', end: '2026-02-28' });
  assert.deepEqual(periodPreset('2100-02-01', 'month'), { start: '2100-02-01', end: '2100-02-28' });
});

test('Los periodos rápidos conservan mes y año sin depender de la zona horaria', () => {
  assert.deepEqual(periodPreset('2026-12-31', 'first'), { start: '2026-12-01', end: '2026-12-15' });
  assert.deepEqual(periodPreset('2026-04-10', 'month'), { start: '2026-04-01', end: '2026-04-30' });
  assert.throws(() => periodPreset('2026-02-29', 'month'));
  assert.throws(() => periodPreset('', 'first'));
});

const payrolls = [
  { id: 1, period_start: '2026-09-01', period_end: '2026-09-15', status: 'paid' },
  { id: 2, period_start: '2026-09-16', period_end: '2026-09-30', status: 'draft' },
  { id: 3, period_start: '2026-10-01', period_end: '2026-10-15', status: 'cancelled' }
];

test('El filtro de fechas incluye periodos parcialmente superpuestos', () => {
  assert.deepEqual(filterPayrolls(payrolls, { from: '2026-09-15', to: '2026-09-16' }).rows.map(row => row.id), [1, 2]);
  assert.deepEqual(filterPayrolls(payrolls, { from: '2026-09-16', status: 'draft' }).rows.map(row => row.id), [2]);
});

test('Un rango invertido se rechaza sin mostrar resultados engañosos', () => {
  const result = filterPayrolls(payrolls, { from: '2026-10-15', to: '2026-09-01' });
  assert.ok(result.error);
  assert.deepEqual(result.rows, []);
  assert.deepEqual(filterPayrolls(payrolls, { query: 'inexistente' }), { rows: [], error: '' });
  assert.equal(filterPayrolls(payrolls, { query: '2026-10' }).rows[0].id, 3);
});
