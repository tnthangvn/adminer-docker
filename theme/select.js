/**
 * Instrument — row inspector and typed search
 *
 * Two things the select page makes slow:
 *
 *   Reading a row. The grid truncates every value to TEXT LENGTH, and a wide
 *   table pushes the interesting columns off the right edge. The inspector
 *   slides a panel over the grid with the whole row, full values pulled from
 *   the edit form, and copy / edit / clone one key away.
 *
 *   Searching a date. Adminer gives every column the same text box, so a
 *   timestamp means typing "2026-07-24 00:00:00" by hand. Column types are
 *   already in the table header, so the value field can match the column:
 *   a date picker for dates, a number spinner for numbers, true/false for
 *   booleans.
 */

(() => {
	'use strict';

	const grid = document.querySelector('#table');
	const form = document.querySelector('#form');
	if (!grid || !form) {
		return;
	}

	/** Column name -> declared type, straight out of the header cells. */
	const types = new Map(
		[...grid.querySelectorAll('thead th[id^="th["]')].map(th => [
			th.id.slice(3, -1),
			th.querySelector('span[title]')?.title || '',
		]));

	function family(type) {
		const t = (type || '').toLowerCase();
		if (/timestamp|datetime/.test(t)) return 'datetime';
		if (/^date/.test(t)) return 'date';
		if (/^time/.test(t)) return 'time';
		if (/bool/.test(t)) return 'boolean';
		if (/json/.test(t)) return 'json';
		if (/int|numeric|decimal|real|double|money|serial/.test(t)) return 'number';
		return 'text';
	}

	async function copy(text, button) {
		try {
			await navigator.clipboard.writeText(text);
		} catch {
			// Clipboard API needs a secure context; over plain http on a LAN
			// address it is not there.
			const pad = document.createElement('textarea');
			pad.value = text;
			document.body.append(pad);
			pad.select();
			document.execCommand('copy');
			pad.remove();
		}
		if (button) {
			const was = button.textContent;
			button.textContent = 'copied';
			setTimeout(() => { button.textContent = was; }, 900);
		}
	}

	/* =====================================================================
	   Typed search
	   ===================================================================== */

	const search = document.querySelector('#fieldset-search');
	if (search) {
		const booleans = document.createElement('datalist');
		booleans.id = 'ig-booleans';
		booleans.innerHTML = '<option value="true"><option value="false">';
		search.append(booleans);

		const INPUT_TYPE = { date: 'date', datetime: 'datetime-local', time: 'time', number: 'number' };

		function adapt(row) {
			const col = row.querySelector('select[name$="[col]"]');
			const op = row.querySelector('select[name$="[op]"]');
			const val = row.querySelector('input[name$="[val]"]');
			if (!col || !val) {
				return;
			}

			const kind = col.value ? family(types.get(col.value)) : 'text';
			const type = INPUT_TYPE[kind] || 'text';

			if (val.type !== type) {
				// Switching away from a picker leaves a value the text box
				// cannot render; drop it rather than submit half of it.
				if (val.type !== 'text' && type === 'text') {
					val.value = '';
				}
				val.type = type;
			}
			// An enum column has a known set of answers; offer them.
			const labels = window.igEnums?.[col.value];
			if (labels) {
				let list = document.getElementById('ig-enum-' + col.value);
				if (!list) {
					list = document.createElement('datalist');
					list.id = 'ig-enum-' + col.value;
					list.innerHTML = labels.map(l => `<option value="${l.replace(/"/g, '&quot;')}">`).join('');
					search.append(list);
				}
			}

			val.setAttribute('list', labels ? 'ig-enum-' + col.value : (kind === 'boolean' ? 'ig-booleans' : ''));
			val.classList.toggle('ig-typed', kind !== 'text');
			row.dataset.igKind = kind;

			// "LIKE %%" is the right default for prose and useless for a
			// timestamp. Only decide while the operator is still untouched.
			if (op && !op.dataset.igTouched && kind !== 'text') {
				const exact = [...op.options].find(o => o.value === '=');
				if (exact) {
					op.value = '=';
				}
			}
		}

		const rows = () => search.querySelectorAll('div:has(> select[name$="[col]"])');

		search.addEventListener('change', event => {
			const row = event.target.closest('div');
			if (event.target.name?.endsWith('[op]')) {
				event.target.dataset.igTouched = '1';
			}
			if (row) {
				adapt(row);
			}
		});

		// Adminer appends a fresh row as soon as you fill the last one.
		new MutationObserver(() => rows().forEach(adapt)).observe(search, { childList: true, subtree: true });
		rows().forEach(adapt);

		// A datetime-local field hands back "2026-07-24T09:30"; SQL wants a space.
		form.addEventListener('submit', () => {
			for (const val of search.querySelectorAll('input[type="datetime-local"]')) {
				val.value = val.value.replace('T', ' ');
			}
		});
	}

	/* =====================================================================
	   Row inspector
	   ===================================================================== */

	const drawer = document.createElement('aside');
	drawer.className = 'ig-drawer';
	drawer.hidden = true;
	drawer.innerHTML = `
		<header class="ig-drawer-head">
			<div>
				<b class="ig-drawer-title"></b>
				<span class="ig-drawer-key"></span>
			</div>
			<button type="button" class="ig-drawer-close" title="Close (Esc)">×</button>
		</header>
		<nav class="ig-tabs">
			<button type="button" data-view="fields" class="ig-on">Fields</button>
			<button type="button" data-view="json">JSON</button>
			<span class="ig-loading" hidden>loading full values…</span>
		</nav>
		<div class="ig-drawer-body"></div>
		<footer class="ig-drawer-foot">
			<button type="button" data-act="copy">Copy JSON</button>
			<button type="button" data-act="edit">Edit</button>
			<button type="button" data-act="clone">Clone</button>
			<span class="ig-nav">↑↓ row · Esc close</span>
		</footer>`;
	document.body.append(drawer);

	const ui = {
		title: drawer.querySelector('.ig-drawer-title'),
		key: drawer.querySelector('.ig-drawer-key'),
		body: drawer.querySelector('.ig-drawer-body'),
		loading: drawer.querySelector('.ig-loading'),
		tabs: [...drawer.querySelectorAll('.ig-tabs button')],
	};

	let row = null;              // the <tr> on show
	let values = {};             // column -> value, full where we have it
	let view = 'fields';
	let token = 0;               // guards against a slow fetch landing late

	const tableName = new URL(location.href).searchParams.get('select') || 'row';

	function readGrid(tr) {
		const out = {};
		for (const cell of tr.querySelectorAll('td[id^="val["]')) {
			const column = cell.id.slice(cell.id.lastIndexOf('[') + 1, -1);
			out[column] = cell.querySelector('i') ? null : cell.textContent;
		}
		return out;
	}

	async function readFull(tr) {
		const link = tr.querySelector('a.edit');
		if (!link) {
			return null;
		}

		const html = await (await fetch(link.href, { credentials: 'same-origin' })).text();
		const doc = new DOMParser().parseFromString(html, 'text/html');
		const out = {};

		for (const field of doc.querySelectorAll('[name^="fields["]')) {
			const name = field.getAttribute('name').slice(7, -1);
			if (field.type === 'checkbox' || field.type === 'radio') {
				if (field.checked) {
					out[name] = field.value;
				}
			} else {
				out[name] = field.tagName === 'TEXTAREA' ? field.textContent : field.value;
			}
		}

		// A null column has no value in the form, only a checked NULL box.
		for (const nul of doc.querySelectorAll('[name^="fields-null["]')) {
			if (nul.checked) {
				out[nul.getAttribute('name').slice(12, -1)] = null;
			}
		}

		return Object.keys(out).length ? out : null;
	}

	function pretty(value, column) {
		if (value === null) {
			return '<i class="ig-null">NULL</i>';
		}
		if (family(types.get(column)) === 'json') {
			try {
				return `<pre class="ig-json">${escape(JSON.stringify(JSON.parse(value), null, 2))}</pre>`;
			} catch { /* not valid JSON after all — fall through */ }
		}
		return escape(value);
	}

	const escape = s => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

	function paint() {
		if (view === 'json') {
			ui.body.innerHTML = `<pre class="ig-json ig-json-all">${escape(JSON.stringify(values, null, 2))}</pre>`;
			return;
		}

		ui.body.innerHTML = `<dl class="ig-fields">${Object.entries(values).map(([column, value]) => `
			<div class="ig-field" data-column="${column}">
				<dt><span class="ig-name">${column}</span><span class="ig-kind">${escape(types.get(column) || '')}</span></dt>
				<dd>${pretty(value, column)}</dd>
			</div>`).join('')}</dl>`;
	}

	async function open(tr) {
		row = tr;
		const mine = ++token;

		for (const other of grid.querySelectorAll('tbody tr.ig-peeking')) {
			other.classList.remove('ig-peeking');
		}
		tr.classList.add('ig-peeking');

		values = readGrid(tr);
		view = 'fields';
		ui.tabs.forEach(t => t.classList.toggle('ig-on', t.dataset.view === 'fields'));
		ui.title.textContent = tableName;
		ui.key.textContent = decodeURIComponent(
			(tr.querySelector('input[name="check[]"]')?.value || '').replace(/^&/, '').replace(/where\[|\]/g, ''));
		paint();

		drawer.hidden = false;
		document.body.classList.add('ig-drawer-open');
		ui.loading.hidden = false;

		try {
			const full = await readFull(tr);
			if (full && mine === token) {
				values = { ...values, ...full };
				paint();
			}
		} catch {
			// The grid values are still on screen; they are just truncated.
		} finally {
			if (mine === token) {
				ui.loading.hidden = true;
			}
		}
	}

	function close() {
		token++;
		drawer.hidden = true;
		document.body.classList.remove('ig-drawer-open');
		row?.classList.remove('ig-peeking');
		row = null;
	}

	function step(delta) {
		if (!row) {
			return;
		}
		const all = [...grid.querySelectorAll('tbody tr')];
		const next = all[all.indexOf(row) + delta];
		if (next) {
			open(next);
			next.scrollIntoView({ block: 'nearest' });
		}
	}

	// --- wiring --------------------------------------------------------------

	for (const tr of grid.querySelectorAll('tbody tr')) {
		const cell = tr.querySelector('td');
		if (!cell || !tr.querySelector('td[id^="val["]')) {
			continue;
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'ig-peek';
		button.title = 'Inspect row';
		button.textContent = '⌗';
		button.onclick = event => {
			// Adminer ticks a row's checkbox on any click inside it; inspecting is
			// not selecting.
			event.stopPropagation();
			row === tr ? close() : open(tr);
		};
		cell.append(button);
	}

	drawer.querySelector('.ig-drawer-close').onclick = close;

	for (const tab of ui.tabs) {
		tab.onclick = () => {
			view = tab.dataset.view;
			ui.tabs.forEach(t => t.classList.toggle('ig-on', t === tab));
			paint();
		};
	}

	drawer.querySelector('.ig-drawer-foot').onclick = event => {
		const act = event.target.dataset?.act;
		if (!act || !row) {
			return;
		}
		if (act === 'copy') {
			copy(JSON.stringify(values, null, 2), event.target);
		}
		if (act === 'edit') {
			row.querySelector('a.edit')?.click();
		}
		if (act === 'clone') {
			// Adminer already knows how to clone a checked row; use its button
			// rather than rebuilding the request.
			const check = row.querySelector('input[name="check[]"]');
			const clone = form.querySelector('input[name="clone"]');
			if (check && clone) {
				grid.querySelectorAll('input[name="check[]"]:checked').forEach(c => { c.checked = false; });
				check.checked = true;
				clone.click();
			}
		}
	};

	// Click any value to copy just that one.
	ui.body.onclick = event => {
		const field = event.target.closest('.ig-field');
		if (field) {
			copy(String(values[field.dataset.column] ?? ''), null);
			field.classList.add('ig-copied');
			setTimeout(() => field.classList.remove('ig-copied'), 700);
		}
	};

	addEventListener('keydown', event => {
		if (drawer.hidden || event.target.matches('input, textarea, select')) {
			return;
		}
		if (event.key === 'Escape') {
			close();
		}
		if (event.key === 'ArrowDown') {
			event.preventDefault();
			step(1);
		}
		if (event.key === 'ArrowUp') {
			event.preventDefault();
			step(-1);
		}
	});
})();
