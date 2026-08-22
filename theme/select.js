/**
 * Instrument — row inspector and typed search
 *
 * Two things the select page makes slow:
 *
 *   Reading a row. The grid truncates every value to TEXT LENGTH, and a wide
 *   table pushes the interesting columns off the right edge. Tick a row — which
 *   is what clicking one already does — and the inspector shows it whole, with
 *   full values pulled from the edit form. Tick a second and both are there,
 *   because comparing two rows is most of why you opened it.
 *
 *   Searching a date. Adminer gives every column the same text box, so a
 *   timestamp means typing "2026-07-24 00:00:00" by hand. Column types are
 *   already in the table header, so the value field can match the column:
 *   a calendar for dates, a number spinner for numbers, true/false for
 *   booleans, the labels for an enum.
 */

(() => {
	'use strict';

	const grid = document.querySelector('#table');
	const form = document.querySelector('#form');
	if (!grid || !form) {
		return;
	}

	const FETCH_LIMIT = 10;   // rows worth a request each for their full values
	const SHOW_LIMIT = 25;    // rows to render at all

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

	const escape = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

	async function copy(text, button) {
		try {
			await navigator.clipboard.writeText(text);
		} catch {
			// The Clipboard API needs a secure context; over plain http on a LAN
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

		// The searchable-select wrapper sits between the select and its row, so
		// walk up from the column select rather than matching on shape.
		const rowOf = el => el.parentElement;
		const rows = () => [...search.querySelectorAll('select[name$="[col]"]')].map(rowOf);

		function adapt(row) {
			if (!row) {
				return;
			}
			const col = row.querySelector('select[name$="[col]"]');
			const op = row.querySelector('select[name$="[op]"]');
			const val = row.querySelector('input[name$="[val]"]');
			if (!col || !val) {
				return;
			}

			const kind = col.value ? family(types.get(col.value)) : 'text';
			const wasTyped = val.dataset.igKind && val.dataset.igKind !== 'text';

			// Dates keep a plain text box holding an unambiguous YYYY-MM-DD; the
			// calendar in datepicker.js drives it. The native field renders in
			// the browser's locale and has nowhere to put a range shortcut.
			if (kind === 'date' || kind === 'datetime') {
				val.type = 'text';
				val.dataset.igDate = kind;
				val.placeholder = kind === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD hh:mm:ss';
				// A plain text box invites Chrome's saved-value dropdown, which
				// lands on top of the calendar.
				val.autocomplete = 'off';
				val.spellcheck = false;
			} else {
				val.autocomplete = '';
				delete val.dataset.igDate;
				val.placeholder = '';
				const type = kind === 'number' ? 'number' : kind === 'time' ? 'time' : 'text';
				if (val.type !== type) {
					if (wasTyped && type === 'text') {
						val.value = '';       // a picker's value is not text-box shaped
					}
					val.type = type;
				}
			}

			// An enum column has a known set of answers; offer them.
			const labels = window.igEnums?.[col.value];
			if (labels && !document.getElementById('ig-enum-' + col.value)) {
				const list = document.createElement('datalist');
				list.id = 'ig-enum-' + col.value;
				list.innerHTML = labels.map(l => `<option value="${escape(l)}">`).join('');
				search.append(list);
			}

			val.setAttribute('list', labels ? 'ig-enum-' + col.value : (kind === 'boolean' ? 'ig-booleans' : ''));
			val.classList.toggle('ig-typed', kind !== 'text');
			val.dataset.igKind = kind;

			// "LIKE %%" is the right default for prose and useless for a
			// timestamp. Only decide while the operator is still untouched.
			if (op && !op.dataset.igTouched && kind !== 'text') {
				const exact = [...op.options].find(o => o.value === '=');
				if (exact && op.value !== '=') {
					op.value = '=';
					op.dispatchEvent(new Event('change', { bubbles: true }));
				}
			}
		}

		search.addEventListener('change', event => {
			if (!event.target.name) {
				return;
			}
			if (event.target.name.endsWith('[op]')) {
				event.target.dataset.igTouched = '1';
			}
			if (event.target.name.endsWith('[col]')) {
				adapt(rowOf(event.target));
			}
		});

		// Adminer appends a fresh row as soon as you fill the last one.
		new MutationObserver(() => rows().forEach(adapt)).observe(search, { childList: true, subtree: true });
		rows().forEach(adapt);
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

	let shown = [];        // [{ tr, key, values }] in grid order
	let view = 'fields';
	let token = 0;         // guards against a slow fetch landing late
	let dismissed = '';    // the selection the drawer was closed on

	const tableName = new URL(location.href).searchParams.get('select') || 'row';
	const keyOf = tr => decodeURIComponent(
		(tr.querySelector('input[name="check[]"]')?.value || '')
			.replace(/^&/, '').replace(/where\[|\]/g, ''));

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

		for (const item of doc.querySelectorAll('[name^="fields["]')) {
			const name = item.getAttribute('name').slice(7, -1);
			if (item.type === 'checkbox' || item.type === 'radio') {
				if (item.checked) {
					out[name] = item.value;
				}
			} else {
				out[name] = item.tagName === 'TEXTAREA' ? item.textContent : item.value;
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

	const fieldList = (row, index) => `<dl class="ig-fields">${Object.entries(row.values).map(([column, value]) => `
		<div class="ig-field" data-row="${index}" data-column="${escape(column)}">
			<dt><span class="ig-name">${escape(column)}</span><span class="ig-kind">${escape(types.get(column) || '')}</span></dt>
			<dd>${pretty(value, column)}</dd>
		</div>`).join('')}</dl>`;

	function paint() {
		const payload = shown.length === 1 ? shown[0].values : shown.map(row => row.values);

		if (view === 'json') {
			ui.body.innerHTML = `<pre class="ig-json ig-json-all">${escape(JSON.stringify(payload, null, 2))}</pre>`;
			return;
		}

		if (shown.length === 1) {
			ui.body.innerHTML = fieldList(shown[0], 0);
			return;
		}

		ui.body.innerHTML = shown.map((row, index) => `
			<details class="ig-row" open>
				<summary><span class="ig-row-n">${index + 1}</span>${escape(row.key)}</summary>
				${fieldList(row, index)}
			</details>`).join('');
	}

	function checkedRows() {
		return [...grid.querySelectorAll('tbody input[name="check[]"]:checked')]
			.map(box => box.closest('tr'))
			.filter(tr => tr?.querySelector('td[id^="val["]'));
	}

	async function render(rows) {
		const mine = ++token;
		const visible = rows.slice(0, SHOW_LIMIT);

		shown = visible.map(tr => ({ tr, key: keyOf(tr), values: readGrid(tr) }));
		ui.title.textContent = rows.length > 1 ? `${tableName} · ${rows.length} rows` : tableName;
		ui.key.textContent = rows.length === 1
			? shown[0].key
			: (rows.length > SHOW_LIMIT ? `showing the first ${SHOW_LIMIT}` : '');
		paint();

		drawer.hidden = false;
		ui.loading.hidden = visible.length > FETCH_LIMIT;

		if (visible.length > FETCH_LIMIT) {
			return;                      // too many to be worth a request each
		}

		try {
			const full = await Promise.all(visible.map(tr => readFull(tr).catch(() => null)));
			if (mine !== token) {
				return;
			}
			full.forEach((values, i) => {
				if (values) {
					shown[i].values = { ...shown[i].values, ...values };
				}
			});
			paint();
		} finally {
			if (mine === token) {
				ui.loading.hidden = true;
			}
		}
	}

	function close() {
		token++;
		drawer.hidden = true;
		dismissed = checkedRows().map(keyOf).join('|');
	}

	/** Follows the grid's own selection: clicking a row already ticks its box. */
	function sync() {
		const rows = checkedRows();
		const signature = rows.map(keyOf).join('|');

		if (!rows.length) {
			token++;
			drawer.hidden = true;
			dismissed = '';
			return;
		}
		if (signature === dismissed) {
			return;                      // closed by hand, and nothing has moved
		}
		dismissed = '';
		render(rows);
	}

	function step(delta) {
		const rows = [...grid.querySelectorAll('tbody tr')].filter(tr => tr.querySelector('td[id^="val["]'));
		const from = shown.length ? rows.indexOf(shown[shown.length - 1].tr) : -1;
		const next = rows[from + delta];
		if (!next) {
			return;
		}

		for (const box of grid.querySelectorAll('tbody input[name="check[]"]')) {
			box.checked = false;
			box.closest('tr')?.classList.remove('checked');
		}
		const box = next.querySelector('input[name="check[]"]');
		if (box) {
			box.checked = true;
			next.classList.add('checked');
		}
		next.scrollIntoView({ block: 'nearest' });
		sync();
	}

	/* --- wiring ------------------------------------------------------------- */

	// Adminer ticks boxes from its own click handler without firing `change`,
	// so read the selection back once the click has been dealt with.
	grid.addEventListener('click', () => setTimeout(sync, 0));
	grid.addEventListener('change', () => setTimeout(sync, 0));

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
		if (!act || !shown.length) {
			return;
		}
		if (act === 'copy') {
			copy(JSON.stringify(shown.length === 1 ? shown[0].values : shown.map(r => r.values), null, 2), event.target);
		}
		if (act === 'edit') {
			// One row has its own edit page; several go through Adminer's bulk
			// edit, which already works on the ticked rows.
			if (shown.length === 1) {
				shown[0].tr.querySelector('a.edit')?.click();
			} else {
				form.querySelector('input[name="edit"]')?.click();
			}
		}
		if (act === 'clone') {
			form.querySelector('input[name="clone"]')?.click();
		}
	};

	// Click any value to copy just that one.
	ui.body.onclick = event => {
		const field = event.target.closest('.ig-field');
		if (field) {
			copy(String(shown[Number(field.dataset.row)]?.values[field.dataset.column] ?? ''), null);
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
