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
 *
 * And three things it makes long-winded:
 *
 *   Dropping a filter. The value box is type=search, so a browser draws its
 *   own clear cross — in a colour the palette never chose. Ours replaces it.
 *
 *   Filtering the column you are looking at. The header knows which column it
 *   is; clicking its name asks only what is left, the test and the value.
 *
 *   Picking columns. One dropdown per column, in a fieldset that starts
 *   collapsed, where a checklist says the same thing in one pass.
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

		// The searchable-select wrapper sits between the select and its row, and
		// the clear button wraps the value box, so walk up to the row `div`
		// rather than assuming a parent.
		const rowOf = el => el.closest('#fieldset-search div') || el.parentElement;
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
	/* =====================================================================
	   Clear button

	   The value box is `type=search`, so a browser draws its own clear cross —
	   one that ignores the palette and disappears into a dark field. Ours sits
	   in the same place, and when the value it drops is the one the page was
	   loaded with, it re-runs the query: clearing a filter is the whole point.
	   ===================================================================== */

	function clearable(input) {
		if (input.dataset.igClear) {
			return;
		}
		input.dataset.igClear = '1';

		const wrap = document.createElement('span');
		wrap.className = 'ig-clearable';
		input.replaceWith(wrap);
		wrap.append(input);

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'ig-clear';
		button.tabIndex = -1;
		button.title = 'Clear';
		button.textContent = '×';
		wrap.append(button);
	}

	// Not `input[type=search]`: the typed search above has already retyped some
	// of these boxes by the time this runs.
	const VALUE_BOX = '#fieldset-search input[name$="[val]"], #fieldset-search input[name^="fulltext"]';

	const clearables = root => root.querySelectorAll?.(VALUE_BOX) ?? [];

	/**
	 * An empty box has nothing to clear, so the cross only shows on a full one.
	 * `:placeholder-shown` would say the same in CSS, but these boxes have no
	 * placeholder, and the typed search rewrites the one they do get.
	 */
	function syncClears() {
		for (const wrap of search?.querySelectorAll('.ig-clearable') ?? []) {
			wrap.classList.toggle('is-on', !!wrap.querySelector('input')?.value);
		}
	}

	if (search) {
		// A row Adminer clones carries a copy of the wrapper and the button.
		// Nothing to rebuild — the handler is on the document, not the button.
		document.addEventListener('click', event => {
			const button = event.target.closest?.('.ig-clear');
			if (!button) {
				return;
			}
			const input = button.parentElement.querySelector('input');
			if (!input) {
				return;
			}

			const submitted = input.defaultValue !== '';
			input.value = '';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			syncClears();
			input.focus();

			// The value came from the server, so it is in the query on screen:
			// dropping it means asking again without it.
			if (submitted) {
				form.submit();
			}
		});

		search.addEventListener('input', syncClears);

		clearables(document).forEach(clearable);
		syncClears();

		new MutationObserver(records => {
			for (const record of records) {
				for (const node of record.addedNodes) {
					if (node.nodeType !== 1) {
						continue;
					}
					if (node.matches?.(VALUE_BOX)) {
						clearable(node);
					}
					clearables(node).forEach(clearable);
				}
			}
			syncClears();
		}).observe(search, { childList: true, subtree: true });
	}

	/* =====================================================================
	   Popovers

	   Two of them: a filter on a column header, and the column picker. Both
	   float over the page, both close on an outside click — except on the
	   combobox list and the calendar, which are their own layers on `body`
	   and belong to whatever is open.
	   ===================================================================== */

	const pops = [];

	function floater(className, markup) {
		const el = document.createElement('div');
		el.className = 'ig-pop ' + className;
		el.hidden = true;
		el.innerHTML = markup;
		document.body.append(el);
		pops.push(el);
		return el;
	}

	function place(el, anchor) {
		const box = anchor.getBoundingClientRect();
		const room = innerHeight - box.bottom - 12;
		const above = room < el.offsetHeight + 12 && box.top > room;

		el.style.left = Math.max(8, Math.min(box.left, innerWidth - el.offsetWidth - 8)) + 'px';
		el.style.top = above ? '' : box.bottom + 4 + 'px';
		el.style.bottom = above ? innerHeight - box.top + 4 + 'px' : '';
	}

	const ownLayer = target =>
		pops.some(el => el.contains(target)) || target.closest?.('.ig-combo-pop, .ig-cal');

	function closePops(except) {
		for (const el of pops) {
			if (el !== except) {
				el.hidden = true;
			}
		}
	}

	addEventListener('pointerdown', event => {
		if (!ownLayer(event.target) && !event.target.closest?.('.ig-pop-anchor')) {
			closePops();
		}
	}, true);

	addEventListener('keydown', event => {
		if (event.key === 'Escape' && pops.some(el => !el.hidden)) {
			event.stopPropagation();
			closePops();
		}
	}, true);

	addEventListener('resize', () => closePops());

	/* =====================================================================
	   Filter from the column header

	   The header already knows the column and its type; the search form is a
	   long way down the page and needs the column picked again. So clicking a
	   column name asks the only two questions left — which test, which value —
	   and submits. Sorting keeps its place inside the same panel, and a
	   modified click still follows the header link.
	   ===================================================================== */

	const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'LIKE %%', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];
	const VALUELESS = /^(IS NULL|IS NOT NULL)$/;

	function operators() {
		const op = search?.querySelector('select[name$="[op]"]');
		const found = op ? [...op.options].map(option => option.value).filter(Boolean) : [];
		return found.length ? found : OPERATORS;
	}

	const filterPop = floater('ig-filter-pop', `
		<div class="ig-pop-head">
			<b class="ig-pop-title"></b><span class="ig-pop-kind"></span>
		</div>
		<div class="ig-pop-row">
			<select class="ig-pop-op"></select>
			<input class="ig-pop-val" type="text" autocomplete="off" spellcheck="false" placeholder="value">
		</div>
		<div class="ig-pop-foot">
			<span class="ig-pop-sort">
				<button type="button" data-sort="asc" title="Sort ascending">↑</button>
				<button type="button" data-sort="desc" title="Sort descending">↓</button>
			</span>
			<button type="button" class="ig-pop-apply">Filter</button>
		</div>`);

	const fui = {
		title: filterPop.querySelector('.ig-pop-title'),
		kind: filterPop.querySelector('.ig-pop-kind'),
		op: filterPop.querySelector('.ig-pop-op'),
		val: filterPop.querySelector('.ig-pop-val'),
	};
	fui.op.innerHTML = operators().map(op => `<option value="${escape(op)}">${escape(op)}</option>`).join('');

	let column = '';       // the column the panel is open on
	let sortHref = null;   // { asc, desc } for that column, when it can be sorted

	/** Shapes the value box the way the typed search shapes its own. */
	function typeValue(kind) {
		const val = fui.val;
		delete val.dataset.igDate;
		val.removeAttribute('list');
		val.placeholder = 'value';

		if (kind === 'date' || kind === 'datetime') {
			val.type = 'text';
			val.dataset.igDate = kind;
			val.placeholder = kind === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD hh:mm:ss';
			return;
		}

		val.type = kind === 'number' ? 'number' : kind === 'time' ? 'time' : 'text';

		const labels = window.igEnums?.[column];
		if (labels) {
			val.setAttribute('list', 'ig-enum-' + column);
		} else if (kind === 'boolean') {
			val.setAttribute('list', 'ig-booleans');
		}
	}

	function openFilter(th, link) {
		column = th.id.slice(3, -1);
		const kind = family(types.get(column));

		fui.title.textContent = column;
		fui.kind.textContent = types.get(column) || '';
		fui.op.value = kind === 'text' && fui.op.querySelector('option[value="LIKE %%"]') ? 'LIKE %%' : '=';
		fui.op.dispatchEvent(new Event('change', { bubbles: true }));   // the combobox label
		fui.val.value = '';
		typeValue(kind);

		const href = link?.getAttribute('href') || '';
		const asc = href.replace(/&desc(%5B|\[)0(%5D|\])=1/gi, '');
		sortHref = href ? { asc, desc: asc + '&desc%5B0%5D=1' } : null;
		filterPop.querySelector('.ig-pop-sort').hidden = !sortHref;

		closePops(filterPop);
		filterPop.hidden = false;
		place(filterPop, th);
		fui.val.focus();
	}

	/**
	 * Writes the condition into the search form and asks again. The form is
	 * the page's own state — submitting it keeps every other condition, the
	 * sort, and the limit, which a hand-built URL would have to copy.
	 */
	function applyFilter() {
		const op = fui.op.value;
		const value = VALUELESS.test(op) ? '' : fui.val.value;
		if (!column || (!value && !VALUELESS.test(op))) {
			return;
		}

		const rows = search
			? [...search.querySelectorAll('select[name$="[col]"]')].map(el => el.closest('#fieldset-search div'))
			: [];

		// A row already on this column and still empty is the one the user
		// means; otherwise take the spare row Adminer keeps at the bottom.
		const row = rows.find(r => r?.querySelector('select[name$="[col]"]').value === column
				&& !r.querySelector('input[name$="[val]"]')?.value)
			|| rows.find(r => r && !r.querySelector('select[name$="[col]"]').value);

		if (!row) {
			return urlFilter(column, op, value);
		}

		const col = row.querySelector('select[name$="[col]"]');
		col.value = column;
		col.dispatchEvent(new Event('change', { bubbles: true }));

		const opSelect = row.querySelector('select[name$="[op]"]');
		if (opSelect && [...opSelect.options].some(option => option.value === op)) {
			opSelect.value = op;
			opSelect.dataset.igTouched = '1';
			opSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

		const val = row.querySelector('input[name$="[val]"]');
		val.value = value;
		val.dispatchEvent(new Event('input', { bubbles: true }));

		closePops();
		form.submit();
	}

	/** Without a search form to fill — no WHERE privilege — build the link. */
	function urlFilter(name, op, value) {
		const url = new URL(location.href);
		let i = 0;
		while (url.searchParams.has(`where[${i}][col]`)) {
			i++;
		}
		url.searchParams.set(`where[${i}][col]`, name);
		url.searchParams.set(`where[${i}][op]`, op);
		url.searchParams.set(`where[${i}][val]`, value);
		url.searchParams.delete('page');
		closePops();
		location.href = url.href;
	}

	grid.addEventListener('click', event => {
		const th = event.target.closest('thead th[id^="th["]');
		if (!th || event.target.closest('.column')) {
			return;                      // the hover controls keep their own jobs
		}
		if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;                      // a modified click still sorts
		}

		// The whole cell opens the panel, not just the four characters of the
		// name: Adminer reads a bare click on a header row as "tick every row",
		// which is not what anyone aiming at a column meant.
		event.preventDefault();
		event.stopPropagation();
		openFilter(th, th.querySelector('a'));
	}, true);

	fui.op.addEventListener('change', () => {
		fui.val.disabled = VALUELESS.test(fui.op.value);
	});

	filterPop.addEventListener('keydown', event => {
		if (event.key === 'Enter') {
			event.preventDefault();
			applyFilter();
		}
	});

	filterPop.querySelector('.ig-pop-apply').onclick = applyFilter;
	filterPop.querySelector('.ig-pop-sort').onclick = event => {
		const sort = event.target.dataset?.sort;
		if (sort && sortHref) {
			location.href = sortHref[sort];
		}
	};

	/* =====================================================================
	   Column picker

	   Limiting the columns means filling one dropdown per column you want to
	   keep, in a fieldset that starts collapsed. A list of checkboxes says
	   the same thing in one pass, and ticking every box is how you get the
	   whole table back — that is plain SELECT *, so the rows go out empty.
	   ===================================================================== */

	const picked = document.querySelector('#fieldset-select');
	const columnSelects = () => picked
		? [...picked.querySelectorAll('select[name^="columns["][name$="[col]"]')]
		: [];

	/** Every column of the table, not just the ones on screen. */
	function allColumns() {
		if (window.igFields) {
			return Object.keys(window.igFields);
		}
		const first = columnSelects()[0];
		return first ? [...first.options].map(option => option.value).filter(Boolean) : [...types.keys()];
	}

	// print_fieldset() puts the id on the div holding the rows, so the legend —
	// which stays visible when that div is collapsed — is one level further up.
	const legend = picked?.closest('fieldset')?.querySelector('legend');

	if (picked && legend && allColumns().length) {
		const columns = allColumns();

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'ig-cols-button ig-pop-anchor';
		legend.append(button);

		const colsPop = floater('ig-cols-pop', `
			<div class="ig-pop-head"><b>Columns</b><span class="ig-cols-count"></span></div>
			<input class="ig-pop-filter" type="text" placeholder="filter" spellcheck="false" autocomplete="off">
			<ul class="ig-cols-list"></ul>
			<div class="ig-pop-foot">
				<button type="button" data-act="all">All</button>
				<button type="button" data-act="none">None</button>
				<button type="button" class="ig-pop-apply" data-act="apply">Show</button>
			</div>`);

		const list = colsPop.querySelector('.ig-cols-list');
		const count = colsPop.querySelector('.ig-cols-count');
		const filter = colsPop.querySelector('.ig-pop-filter');

		const chosen = () => columnSelects().map(select => select.value).filter(Boolean);
		const ticked = () => [...list.querySelectorAll('input:checked')].map(box => box.value);

		list.innerHTML = columns.map(name => `
			<li class="ig-cols-item">
				<label>
					<input type="checkbox" value="${escape(name)}">
					<span class="ig-cols-name">${escape(name)}</span>
					<span class="ig-cols-kind">${escape(window.igFields?.[name] || types.get(name) || '')}</span>
				</label>
			</li>`).join('');

		function label() {
			const on = chosen();
			button.textContent = on.length ? `columns ${on.length}/${columns.length}` : `columns: all`;
		}

		function tally() {
			const on = ticked().length;
			count.textContent = on && on < columns.length ? `${on} of ${columns.length}` : 'all';
		}

		function sync() {
			const on = new Set(chosen());
			for (const box of list.querySelectorAll('input')) {
				box.checked = !on.size || on.has(box.value);
			}
			tally();
		}

		/**
		 * Fills the existing dropdowns in order, then carries whatever is left
		 * in hidden fields — the fieldset only ever renders one spare row, and
		 * this is the same form either way.
		 */
		function applyColumns() {
			const want = ticked();
			const keep = (!want.length || want.length === columns.length) ? [] : want;
			const selects = columnSelects();

			selects.forEach((select, i) => {
				const value = keep[i] || '';
				if (select.value === value) {
					return;
				}
				select.value = value;
				select.dispatchEvent(new Event('change', { bubbles: true }));

				// The function belonged to the column that was there before.
				const fun = select.closest('div')?.querySelector('select[name$="[fun]"]');
				if (fun && fun.value) {
					fun.value = '';
					fun.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});

			const extra = document.createElement('span');
			extra.hidden = true;
			for (const [i, name] of keep.slice(selects.length).entries()) {
				const field = document.createElement('input');
				field.type = 'hidden';
				field.name = `columns[${selects.length + i}][col]`;
				field.value = name;
				extra.append(field);
			}
			form.append(extra);

			closePops();
			form.submit();
		}

		button.onclick = () => {
			if (!colsPop.hidden) {
				return closePops();
			}
			sync();
			filter.value = '';
			list.querySelectorAll('.ig-cols-item').forEach(item => { item.hidden = false; });
			closePops(colsPop);
			colsPop.hidden = false;
			place(colsPop, button);
			filter.focus();
		};

		filter.addEventListener('input', () => {
			const needle = filter.value.trim().toLowerCase();
			for (const item of list.querySelectorAll('.ig-cols-item')) {
				item.hidden = !!needle && !item.textContent.toLowerCase().includes(needle);
			}
		});

		list.addEventListener('change', tally);

		colsPop.addEventListener('keydown', event => {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyColumns();
			}
		});

		colsPop.querySelector('.ig-pop-foot').onclick = event => {
			const act = event.target.dataset?.act;
			if (act === 'apply') {
				applyColumns();
			}
			if (act === 'all' || act === 'none') {
				// Only what the filter box is showing, so a filtered list can
				// be ticked in one go without touching the rest.
				for (const item of list.querySelectorAll('.ig-cols-item')) {
					if (!item.hidden) {
						item.querySelector('input').checked = act === 'all';
					}
				}
				tally();
			}
		};

		label();
	}
})();
