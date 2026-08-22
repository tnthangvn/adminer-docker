/**
 * Instrument — searchable selects
 *
 * A table with forty columns turns every "which column?" dropdown into a
 * scroll hunt: sort, search, foreign keys, the database list. Past ten options
 * a list needs a filter, so any select longer than that gets one.
 *
 * The native <select> stays in the DOM and stays authoritative — it is what the
 * form submits, and Adminer has already bound its own onchange handlers to it
 * by the time this runs. The combobox only sets its value and fires `change`,
 * so everything downstream behaves exactly as if you had used the dropdown.
 */

(() => {
	'use strict';

	const THRESHOLD = 10;

	const pop = document.createElement('div');
	pop.className = 'ig-combo-pop';
	pop.hidden = true;
	pop.innerHTML = `
		<input class="ig-combo-search" type="text" placeholder="filter" spellcheck="false" autocomplete="off">
		<ul class="ig-combo-list" role="listbox"></ul>
		<p class="ig-combo-empty" hidden>no match</p>`;
	document.body.append(pop);

	const search = pop.querySelector('.ig-combo-search');
	const list = pop.querySelector('.ig-combo-list');
	const empty = pop.querySelector('.ig-combo-empty');

	const wired = new WeakSet();   // selects this script owns
	let open = null;        // { select, button }
	let items = [];         // { index, label, group } for the open select
	let active = -1;

	/* --- attaching ---------------------------------------------------------- */

	function label(select) {
		const option = select.selectedOptions[0];
		return option ? option.textContent.trim() : '';
	}

	function attach(select) {
		if (wired.has(select) || select.multiple || select.size > 1
			|| select.options.length <= THRESHOLD || select.closest('.ig-combo-pop')) {
			return;
		}
		wired.add(select);

		const wrap = document.createElement('div');
		wrap.className = 'ig-combo';

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'ig-combo-button';
		button.setAttribute('aria-haspopup', 'listbox');
		button.setAttribute('aria-expanded', 'false');
		button.textContent = label(select);

		select.replaceWith(wrap);
		wrap.append(select, button);
		select.classList.add('ig-combo-native');

		button.onclick = () => (open?.select === select ? close() : show(select, button));

		// Anything that changes the select behind our back — Adminer's own
		// scripts do, when a driver or a database is picked — must show up.
		select.addEventListener('change', () => {
			button.textContent = label(select);
			button.classList.toggle('ig-combo-unset', !select.value);
		});
		button.classList.toggle('ig-combo-unset', !select.value);
	}

	/* --- the popup ---------------------------------------------------------- */

	function show(select, button) {
		open = { select, button };
		button.setAttribute('aria-expanded', 'true');

		items = [...select.options].map((option, index) => ({
			index,
			label: option.textContent.trim(),
			group: option.parentElement.tagName === 'OPTGROUP' ? option.parentElement.label : '',
			disabled: option.disabled,
		}));

		search.value = '';
		paint('');
		pop.hidden = false;
		place();
		search.focus();
	}

	function close() {
		if (!open) {
			return;
		}
		open.button.setAttribute('aria-expanded', 'false');
		pop.hidden = true;
		open = null;
		active = -1;
	}

	function place() {
		const box = open.button.getBoundingClientRect();
		const room = innerHeight - box.bottom - 12;
		const above = room < 200 && box.top > room;

		pop.style.left = Math.min(box.left, innerWidth - pop.offsetWidth - 8) + 'px';
		pop.style.minWidth = Math.max(box.width, 200) + 'px';
		pop.style.maxHeight = Math.max(160, above ? box.top - 12 : room) + 'px';
		pop.style.top = above ? '' : box.bottom + 4 + 'px';
		pop.style.bottom = above ? innerHeight - box.top + 4 + 'px' : '';
	}

	function paint(query) {
		const needle = query.trim().toLowerCase();
		const shown = items.filter(item => !needle || item.label.toLowerCase().includes(needle));

		list.replaceChildren(...shown.map(item => {
			const li = document.createElement('li');
			li.role = 'option';
			li.dataset.index = item.index;
			li.textContent = item.label || ' ';
			li.className = 'ig-combo-item'
				+ (item.index === open.select.selectedIndex ? ' ig-combo-current' : '')
				+ (item.disabled ? ' ig-combo-disabled' : '');
			if (item.group) {
				li.dataset.group = item.group;
			}
			return li;
		}));

		empty.hidden = shown.length > 0;
		active = shown.length ? 0 : -1;
		mark();
	}

	function mark() {
		const all = [...list.children];
		all.forEach((li, i) => li.classList.toggle('ig-combo-active', i === active));
		all[active]?.scrollIntoView({ block: 'nearest' });
	}

	function pick(li) {
		if (!li || li.classList.contains('ig-combo-disabled')) {
			return;
		}
		const { select, button } = open;
		select.selectedIndex = Number(li.dataset.index);
		button.textContent = label(select);
		button.classList.toggle('ig-combo-unset', !select.value);
		close();
		button.focus();

		select.dispatchEvent(new Event('input', { bubbles: true }));
		select.dispatchEvent(new Event('change', { bubbles: true }));
	}

	/* --- wiring ------------------------------------------------------------- */

	search.addEventListener('input', () => paint(search.value));

	search.addEventListener('keydown', event => {
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			active = Math.max(0, Math.min(list.children.length - 1, active + (event.key === 'ArrowDown' ? 1 : -1)));
			mark();
		} else if (event.key === 'Enter') {
			event.preventDefault();
			pick(list.children[active]);
		} else if (event.key === 'Escape') {
			event.preventDefault();
			const button = open?.button;
			close();
			button?.focus();
		} else if (event.key === 'Tab') {
			close();
		}
	});

	list.addEventListener('click', event => pick(event.target.closest('.ig-combo-item')));

	addEventListener('pointerdown', event => {
		if (open && !pop.contains(event.target) && event.target !== open.button) {
			close();
		}
	}, true);

	addEventListener('resize', () => open && place());
	addEventListener('scroll', () => open && place(), true);

	const scan = root => root.querySelectorAll?.('select') ?? [];

	/**
	 * Adminer grows the search form by cloning the last condition row. The clone
	 * carries a copy of our button — same label, no handler, wired to nothing.
	 * A cloned select is a different object, so the WeakSet tells them apart:
	 * unwrap anything we do not own and build it again.
	 */
	function revive(root) {
		for (const wrap of root.querySelectorAll?.('.ig-combo') ?? []) {
			const select = wrap.querySelector('select');
			if (!select || wired.has(select)) {
				continue;
			}
			wrap.querySelectorAll('.ig-combo-button').forEach(button => button.remove());
			select.classList.remove('ig-combo-native');
			wrap.replaceWith(select);
			attach(select);
		}
	}

	scan(document).forEach(attach);

	// Adminer builds rows as you use the page: another search condition, the
	// column list after switching table.
	new MutationObserver(records => {
		for (const record of records) {
			for (const node of record.addedNodes) {
				if (node.nodeType !== 1) {
					continue;
				}
				if (node.tagName === 'SELECT') {
					attach(node);
				}
				revive(node);
				scan(node).forEach(attach);
			}
		}
	}).observe(document.body, { childList: true, subtree: true });
})();
