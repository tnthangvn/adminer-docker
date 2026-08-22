/**
 * Instrument — schema walker
 *
 * Adminer draws the whole schema as one absolutely-positioned column that is
 * thousands of ems tall. At 155 tables that is not a diagram, it is a list with
 * lines drawn over it. This replaces it with the view you actually work in:
 * one table in focus, what it references on the left, what references it on the
 * right, and a click to walk to any neighbour.
 *
 * Everything is read back out of Adminer's own markup — no extra query:
 *   .table            a table, <b> its name, <span> its columns, <i> primary key
 *   .references       an outgoing foreign key; title = the table it points at
 *   .references.arrow an incoming one;         title = the table pointing here
 *   style="top: Nem"  the column the key sits on, in 1.25em rows
 */

(() => {
	'use strict';

	const source = document.querySelector('#schema');
	if (!source || !source.querySelector('.table')) {
		return;
	}

	const ROW_EM = 1.25;              // Adminer's row pitch, and the basis of "top"
	const still = matchMedia('(prefers-reduced-motion: reduce)').matches;

	// --- read Adminer's diagram ---------------------------------------------

	/** @type {Map<string, {name: string, href: string, columns: Array, out: Array, in: Array}>} */
	const tables = new Map();

	for (const box of source.querySelectorAll('.table')) {
		const link = box.querySelector('a');
		const name = link.textContent.trim();

		tables.set(name, {
			name,
			href: link.getAttribute('href'),
			columns: [...box.querySelectorAll('span')].map(span => ({
				name: span.textContent,
				type: span.title,
				kind: span.className,
				primary: span.parentElement.tagName === 'I',
			})),
			out: [],
			in: [],
		});
	}

	for (const box of source.querySelectorAll('.table')) {
		const name = box.querySelector('a').textContent.trim();
		const here = tables.get(name);

		for (const ref of box.querySelectorAll('.references')) {
			const row = Math.round(parseFloat(ref.style.top) / ROW_EM) - 1;
			const other = ref.title;
			if (!tables.has(other)) {
				continue;
			}

			if (ref.classList.contains('arrow')) {
				// Someone points at us, on our column `row`.
				here.in.push({ table: other, column: here.columns[row]?.name });
			} else {
				here.out.push({ table: other, column: here.columns[row]?.name });
			}
		}
	}

	const degree = t => t.out.length + t.in.length;
	const byName = (a, b) => a.table.localeCompare(b.table);
	const unique = list => [...new Map(list.map(r => [r.table + '.' + r.column, r])).values()];

	// --- build the shell -----------------------------------------------------

	const stage = document.createElement('div');
	stage.className = 'ig-schema';
	stage.innerHTML = `
		<div class="ig-bar">
			<input class="ig-search" type="search" placeholder="jump to table" list="ig-tables" spellcheck="false">
			<datalist id="ig-tables">${[...tables.keys()].map(n => `<option value="${n}">`).join('')}</datalist>
			<nav class="ig-trail"></nav>
			<button class="ig-raw" type="button">Adminer diagram</button>
		</div>
		<div class="ig-stage">
			<svg class="ig-links" aria-hidden="true"></svg>
			<div class="ig-side ig-parents"></div>
			<div class="ig-focus"></div>
			<div class="ig-side ig-children"></div>
		</div>`;
	source.parentNode.insertBefore(stage, source);
	document.querySelector('#content').classList.add('ig-walking');

	const els = {
		search: stage.querySelector('.ig-search'),
		trail: stage.querySelector('.ig-trail'),
		raw: stage.querySelector('.ig-raw'),
		stage: stage.querySelector('.ig-stage'),
		links: stage.querySelector('.ig-links'),
		parents: stage.querySelector('.ig-parents'),
		focus: stage.querySelector('.ig-focus'),
		children: stage.querySelector('.ig-children'),
	};

	els.raw.onclick = () => {
		const showing = document.querySelector('#content').classList.toggle('ig-walking');
		els.raw.textContent = showing ? 'Adminer diagram' : 'Schema walker';
	};

	// --- render --------------------------------------------------------------

	let current = null;
	const trail = [];

	function card(table, side, edge) {
		const el = document.createElement('article');
		el.className = 'ig-card ig-' + side;
		el.dataset.table = table.name;

		const via = side === 'parents'
			? `${edge.column} →`
			: `→ ${edge.column ?? ''}`;

		el.innerHTML = `
			<header><b>${table.name}</b><span class="ig-via">${via}</span></header>
			<p class="ig-meta">${table.columns.length} columns · ${degree(table)} keys</p>`;
		el.onclick = () => walk(table.name);

		el.onmouseenter = () => stage.classList.add('ig-tracing') || trace(table.name, true);
		el.onmouseleave = () => { stage.classList.remove('ig-tracing'); trace(table.name, false); };

		return el;
	}

	function focusCard(table) {
		const el = document.createElement('article');
		el.className = 'ig-card ig-focus-card';

		const outBy = new Map(table.out.map(r => [r.column, r.table]));
		const inBy = new Set(table.in.map(r => r.column));

		el.innerHTML = `
			<header>
				<b>${table.name}</b>
				<a class="ig-open" href="${table.href}">structure</a>
			</header>
			<ul class="ig-cols">${table.columns.map(c => `
				<li data-column="${c.name}" class="${c.primary ? 'ig-pk' : ''}">
					<span class="ig-col ${c.kind}">${c.name}</span>
					<span class="ig-type">${c.type}</span>
					${outBy.has(c.name) ? `<span class="ig-fk">→ ${outBy.get(c.name)}</span>` : ''}
					${inBy.has(c.name) ? '<span class="ig-ref">referenced</span>' : ''}
				</li>`).join('')}</ul>`;

		return el;
	}

	function render() {
		const table = tables.get(current);
		els.parents.replaceChildren();
		els.children.replaceChildren();
		els.focus.replaceChildren(focusCard(table));

		const parents = unique(table.out).sort(byName);
		const children = unique(table.in).sort(byName);

		els.parents.replaceChildren(
			heading('references', parents.length),
			...parents.map(edge => card(tables.get(edge.table), 'parents', edge)));
		els.children.replaceChildren(
			heading('referenced by', children.length),
			...children.map(edge => card(tables.get(edge.table), 'children', edge)));

		els.trail.replaceChildren(...trail.map((name, i) => {
			const a = document.createElement(i === trail.length - 1 ? 'span' : 'a');
			a.textContent = name;
			if (a.tagName === 'A') {
				a.href = '#' + name;
				a.onclick = event => { event.preventDefault(); walk(name, i); };
			}
			return a;
		}));

		requestAnimationFrame(draw);
	}

	function heading(text, count) {
		const h = document.createElement('h3');
		h.className = 'ig-side-head';
		h.textContent = count ? `${text} (${count})` : `no ${text}`;
		h.classList.toggle('ig-empty', !count);
		return h;
	}

	// --- the lines -----------------------------------------------------------

	function draw() {
		const box = els.stage.getBoundingClientRect();
		els.links.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);
		els.links.style.width = box.width + 'px';
		els.links.style.height = box.height + 'px';
		els.links.replaceChildren();

		const focusBox = els.focus.querySelector('.ig-focus-card').getBoundingClientRect();

		for (const el of stage.querySelectorAll('.ig-side .ig-card')) {
			const side = el.classList.contains('ig-parents') ? 'parents' : 'children';
			const cardBox = el.getBoundingClientRect();
			const railBox = el.parentElement.getBoundingClientRect();

			// A column of ninety tables scrolls; drawing to the ones you cannot
			// see would just aim lines at the edge of the list.
			if (cardBox.bottom < railBox.top || cardBox.top > railBox.bottom) {
				continue;
			}
			const column = anchorFor(el.dataset.table, side);

			// A key always flows towards the table being referenced, so the
			// path is drawn child-end first and the dashes run along it.
			const from = side === 'parents'
				? { x: focusBox.left, y: column }
				: { x: cardBox.left, y: cardBox.top + cardBox.height / 2 };
			const to = side === 'parents'
				? { x: cardBox.right, y: cardBox.top + cardBox.height / 2 }
				: { x: focusBox.right, y: column };

			els.links.append(path(from, to, box, el.dataset.table));
		}
	}

	/** Vertical centre of the focus row this edge lands on, in viewport pixels. */
	function anchorFor(other, side) {
		const table = tables.get(current);
		const edge = (side === 'parents' ? table.out : table.in).find(r => r.table === other);
		const row = edge && els.focus.querySelector(`[data-column="${CSS.escape(edge.column ?? '')}"]`);
		const target = row || els.focus.querySelector('.ig-focus-card header');
		const rect = target.getBoundingClientRect();

		return rect.top + rect.height / 2;
	}

	function path(from, to, box, table) {
		const x1 = from.x - box.left, y1 = from.y - box.top;
		const x2 = to.x - box.left, y2 = to.y - box.top;
		const bend = Math.max(28, Math.abs(x2 - x1) * 0.42);

		const el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		el.setAttribute('d', `M${x1},${y1} C${x1 + bend},${y1} ${x2 - bend},${y2} ${x2},${y2}`);
		el.setAttribute('class', 'ig-link' + (still ? '' : ' ig-flowing'));
		el.dataset.table = table;

		return el;
	}

	function trace(table, on) {
		for (const link of els.links.querySelectorAll('.ig-link')) {
			link.classList.toggle('ig-lit', on && link.dataset.table === table);
		}
	}

	// --- navigation ----------------------------------------------------------

	function walk(name, truncateAt) {
		if (!tables.has(name)) {
			return;
		}
		if (truncateAt === undefined) {
			trail.push(name);
			if (trail.length > 8) {
				trail.shift();
			}
		} else {
			trail.length = truncateAt + 1;
		}

		current = name;
		history.replaceState(null, '', '#' + name);
		render();
	}

	els.search.onchange = () => {
		if (tables.has(els.search.value)) {
			walk(els.search.value);
			els.search.value = '';
			els.search.blur();
		}
	};

	addEventListener('resize', () => requestAnimationFrame(draw));
	addEventListener('hashchange', () => walk(decodeURIComponent(location.hash.slice(1))));
	for (const side of [els.parents, els.children]) {
		side.addEventListener('scroll', () => requestAnimationFrame(draw), { passive: true });
	}

	// Open on the deep link if there is one, otherwise on the busiest table —
	// the hub is where you would have started looking anyway.
	const hash = decodeURIComponent(location.hash.slice(1));
	const busiest = [...tables.values()].sort((a, b) => degree(b) - degree(a))[0];
	walk(tables.has(hash) ? hash : busiest.name);
})();
