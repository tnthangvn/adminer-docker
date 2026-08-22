/**
 * Instrument — PostgreSQL enum support
 *
 * A PostgreSQL enum is a named type, so Adminer only ever sees the name:
 * the structure page prints `"EMPLOYMENT_TYPE"` and the edit form hands you a
 * free text box. The labels come down from the server in window.igEnums
 * (column => allowed values); this puts them where they are needed.
 *
 *   structure page — the allowed values listed under the type
 *   edit form      — a real <select>, so a typo is not possible
 *   select grid    — enum values picked out in the type colour
 */

(() => {
	'use strict';

	const enums = window.igEnums;
	if (!enums || !Object.keys(enums).length) {
		return;
	}

	const escape = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

	/* --- structure page: say what may go in the column --------------------- */

	if (location.search.includes('&table=')) {
		for (const tr of document.querySelectorAll('#content table tbody tr')) {
			const name = tr.querySelector('th')?.textContent.trim();
			const labels = enums[name];
			const cell = tr.children[1];
			if (!labels || !cell) {
				continue;
			}
			const list = document.createElement('div');
			list.className = 'ig-enum-list';
			list.innerHTML = labels.map(l => `<span class="ig-enum">${escape(l)}</span>`).join('');
			cell.append(list);
		}
	}

	/* --- edit form: a dropdown instead of a text box ------------------------ */

	for (const [column, labels] of Object.entries(enums)) {
		const field = document.querySelector(`[name="fields[${CSS.escape(column)}]"]`);
		if (!field || field.tagName === 'SELECT') {
			continue;
		}

		const current = field.tagName === 'TEXTAREA' ? field.textContent : field.value;
		const select = document.createElement('select');
		select.name = field.name;
		select.className = 'ig-enum-select';
		select.innerHTML = '<option value="">(empty)</option>'
			+ labels.map(l => `<option value="${escape(l)}">${escape(l)}</option>`).join('')
			// Keep whatever is stored even if the type has moved on since.
			+ (current && !labels.includes(current) ? `<option value="${escape(current)}">${escape(current)} (not in type)</option>` : '');
		select.value = current;

		field.replaceWith(select);
	}

	/* --- select grid: enum values read as values, not prose ---------------- */

	const grid = document.querySelector('#table');
	if (grid) {
		const positions = [...grid.querySelectorAll('thead th[id^="th["]')]
			.map(th => th.id.slice(3, -1));

		for (const cell of grid.querySelectorAll('tbody td[id^="val["]')) {
			const column = cell.id.slice(cell.id.lastIndexOf('[') + 1, -1);
			if (enums[column] && positions.includes(column)) {
				cell.classList.add('enum');
			}
		}
	}
})();
