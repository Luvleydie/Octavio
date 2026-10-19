'use strict';

globalThis.NominaUI = (() => {
  // Quitar acentos y espacios permite buscar "Jose" y encontrar "José".
  function searchable(value) {
    return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-MX').trim();
  }

  function paginate(items, requestedPage = 1, pageSize = 10) {
    let size = 10;
    if (Number.isInteger(pageSize) && pageSize > 0) {
      size = pageSize;
    }

    const pages = Math.max(1, Math.ceil(items.length / size));
    let page = 1;
    if (Number.isInteger(requestedPage)) {
      page = requestedPage;
    }
    if (page < 1) page = 1;
    if (page > pages) page = pages;

    const offset = (page - 1) * size;
    return {
      items: items.slice(offset, offset + size),
      page,
      pages,
      total: items.length,
      start: items.length ? offset + 1 : 0,
      end: Math.min(offset + size, items.length)
    };
  }

  function periodPreset(date, preset) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !['first', 'second', 'month'].includes(preset)) {
      throw new Error('Selecciona una fecha y un periodo válidos.');
    }
    const [year, month, day] = date.split('-').map(Number);
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    if (year < 1900 || year > 9999 || month < 1 || month > 12 || day < 1 || day > lastDay) {
      throw new Error('Selecciona una fecha válida entre 1900 y 9999.');
    }
    const prefix = date.slice(0, 7);
    return {
      start: `${prefix}-${preset === 'second' ? '16' : '01'}`,
      end: `${prefix}-${preset === 'first' ? '15' : String(lastDay).padStart(2, '0')}`
    };
  }

  function filterPayrolls(rows, { query = '', status = 'all', from = '', to = '' } = {}) {
    if (from && to && from > to) {
      return { rows: [], error: 'La fecha inicial debe ser igual o anterior al cierre.' };
    }

    const needle = searchable(query);
    const filtered = [];
    for (const row of rows) {
      const matchesStatus = status === 'all' || row.status === status;
      // Un periodo coincide aunque solo una parte quede dentro del rango.
      const matchesFrom = !from || row.period_end >= from;
      const matchesTo = !to || row.period_start <= to;
      const text = row.search_text ?? `${row.id} ${row.period_start} ${row.period_end}`;
      const matchesSearch = searchable(text).includes(needle);

      if (matchesStatus && matchesFrom && matchesTo && matchesSearch) {
        filtered.push(row);
      }
    }
    return { rows: filtered, error: '' };
  }

  return { searchable, paginate, periodPreset, filterPayrolls };
})();
