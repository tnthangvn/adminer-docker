/**
 * Instrument — date picker for the search form
 *
 * The native date field is locale-bound (`mm/dd/yyyy` on an en-US browser) and
 * has nowhere to put the thing you actually want, which is a range: rows from
 * the last seven days, this month, yesterday. So the field goes back to plain
 * text holding an unambiguous `YYYY-MM-DD`, and this draws the calendar.
 *
 * A range shortcut writes two conditions, because that is what a range is:
 * `>= start` on the row you opened, and `< end` on the next one. Adminer keeps
 * a spare empty row at the bottom of the search form, which is exactly where
 * the second half goes. On an edit form you are entering one value, so the
 * shortcuts step aside and only the calendar shows.
 */

(() => {
	'use strict';

	const search = document.querySelector('#fieldset-search');

	const DAY = 86400000;
	const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

	const pad = n => String(n).padStart(2, '0');
	const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	const midnight = d => new Date(d.getFullYear(), d.getMonth(), d.getDate());
	const today = () => midnight(new Date());

	function parse(value) {
		const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '');
		return match ? new Date(+match[1], +match[2] - 1, +match[3]) : null;
	}

	const timeOf = value => (/\d{2}:\d{2}(:\d{2})?/.exec(value || '') || [''])[0];

	/** The condition row an element sits in, past the combobox wrapper. */
	const rowOf = el => el.parentElement;

	/**
	 * The edit form renders every column as a bare text box, so the types come
	 * down from the server in window.igFields.
	 */
	function markEditFields() {
		const types = window.igFields;
		if (!types) {
			return;
		}

		for (const [column, type] of Object.entries(types)) {
			const t = type.toLowerCase();
			const kind = /timestamp|datetime/.test(t) ? 'datetime' : (/^date/.test(t) ? 'date' : '');
			if (!kind) {
				continue;
			}

			const input = document.querySelector(`input[name="fields[${CSS.escape(column)}]"]`);
			if (input && !input.dataset.igDate && input.type !== 'checkbox') {
				input.type = 'text';
				input.dataset.igDate = kind;
				input.placeholder = kind === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD hh:mm:ss';
			}
		}
	}

	/* --- the panel ---------------------------------------------------------- */

	const cal = document.createElement('div');
	cal.className = 'ig-cal';
	cal.hidden = true;
	cal.innerHTML = `
		<div class="ig-cal-shortcuts">
			<button type="button" data-range="today">today</button>
			<button type="button" data-range="yesterday">yesterday</button>
			<button type="button" data-range="7">last 7 days</button>
			<button type="button" data-range="30">last 30 days</button>
			<button type="button" data-range="month">this month</button>
			<button type="button" data-range="lastmonth">last month</button>
		</div>
		<div class="ig-cal-main">
			<header>
				<button type="button" class="ig-cal-step" data-step="-1" title="Previous month">‹</button>
				<b class="ig-cal-month"></b>
				<button type="button" class="ig-cal-step" data-step="1" title="Next month">›</button>
			</header>
			<div class="ig-cal-week">${WEEKDAYS.map(d => `<span>${d}</span>`).join('')}</div>
			<div class="ig-cal-grid"></div>
			<footer>
				<label class="ig-cal-time" hidden>time <input type="text" placeholder="00:00:00" spellcheck="false"></label>
				<button type="button" class="ig-cal-clear">clear</button>
			</footer>
		</div>`;
	document.body.append(cal);

	const ui = {
		shortcuts: cal.querySelector('.ig-cal-shortcuts'),
		month: cal.querySelector('.ig-cal-month'),
		grid: cal.querySelector('.ig-cal-grid'),
		time: cal.querySelector('.ig-cal-time'),
		timeInput: cal.querySelector('.ig-cal-time input'),
	};

	let field = null;      // the input being edited
	let cursor = today();  // month on show

	function open(input) {
		field = input;
		cursor = parse(input.value) || today();

		const withTime = input.dataset.igDate === 'datetime';
		ui.shortcuts.hidden = !search?.contains(input);   // a range needs two conditions
		ui.time.hidden = !withTime;
		ui.timeInput.value = withTime ? (timeOf(input.value) || '') : '';

		cal.hidden = false;
		draw();
		place();
	}

	function close() {
		cal.hidden = true;
		field = null;
	}

	function place() {
		const box = field.getBoundingClientRect();
		const below = innerHeight - box.bottom - 10;
		const above = below < cal.offsetHeight && box.top > below;

		cal.style.left = Math.max(8, Math.min(box.left, innerWidth - cal.offsetWidth - 8)) + 'px';
		cal.style.top = above ? '' : box.bottom + 4 + 'px';
		cal.style.bottom = above ? innerHeight - box.top + 4 + 'px' : '';
	}

	function draw() {
		ui.month.textContent = `${cursor.getFullYear()} · ${pad(cursor.getMonth() + 1)}`;

		const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
		const lead = (first.getDay() + 6) % 7;           // weeks start on Monday
		const start = new Date(first - lead * DAY);
		const chosen = parse(field.value);
		const now = today();

		const cells = [];
		for (let i = 0; i < 42; i++) {
			const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
			const classes = ['ig-cal-day'];
			if (day.getMonth() !== cursor.getMonth()) {
				classes.push('ig-cal-outside');
			}
			if (+day === +now) {
				classes.push('ig-cal-today');
			}
			if (chosen && +day === +chosen) {
				classes.push('ig-cal-chosen');
			}
			cells.push(`<button type="button" class="${classes.join(' ')}" data-date="${iso(day)}">${day.getDate()}</button>`);
		}
		ui.grid.innerHTML = cells.join('');
	}

	/* --- writing back ------------------------------------------------------- */

	function fire(input) {
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function setOperator(row, op) {
		const select = row.querySelector('select[name$="[op]"]');
		if (select && [...select.options].some(o => o.value === op)) {
			select.value = op;
			select.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	function pickDay(isoDate) {
		const time = ui.time.hidden ? '' : ui.timeInput.value.trim();
		field.value = time ? `${isoDate} ${time}` : isoDate;
		fire(field);
		draw();
	}

	/**
	 * Half-open on purpose: `>= start` and `< end`. A timestamp column with
	 * `<= today` would drop everything that happened today.
	 */
	function applyRange(start, end) {
		if (!search?.contains(field)) {
			pickDay(iso(start));
			return close();
		}

		const row = rowOf(field);
		const column = row.querySelector('select[name$="[col]"]')?.value;

		field.value = iso(start);
		fire(field);
		setOperator(row, '>=');

		const spare = [...search.querySelectorAll('select[name$="[col]"]')]
			.map(rowOf)
			.find(other => other !== row && !other.querySelector('input[name$="[val]"]')?.value);

		if (spare && column) {
			const col = spare.querySelector('select[name$="[col]"]');
			col.value = column;
			col.dispatchEvent(new Event('change', { bubbles: true }));

			const val = spare.querySelector('input[name$="[val]"]');
			val.value = iso(end);
			fire(val);
			setOperator(spare, '<');
		}

		close();
	}

	function shortcut(name) {
		const now = today();
		const shift = days => new Date(now.getFullYear(), now.getMonth(), now.getDate() + days);
		const monthStart = offset => new Date(now.getFullYear(), now.getMonth() + offset, 1);

		if (name === 'today') {
			return applyRange(now, shift(1));
		}
		if (name === 'yesterday') {
			return applyRange(shift(-1), now);
		}
		if (name === 'month') {
			return applyRange(monthStart(0), monthStart(1));
		}
		if (name === 'lastmonth') {
			return applyRange(monthStart(-1), monthStart(0));
		}
		return applyRange(shift(1 - Number(name)), shift(1));
	}

	/* --- wiring ------------------------------------------------------------- */

	cal.addEventListener('click', event => {
		const button = event.target.closest('button');
		if (!button || !field) {
			return;
		}
		if (button.dataset.date) {
			pickDay(button.dataset.date);
		} else if (button.dataset.step) {
			cursor = new Date(cursor.getFullYear(), cursor.getMonth() + Number(button.dataset.step), 1);
			draw();
		} else if (button.dataset.range) {
			shortcut(button.dataset.range);
		} else if (button.classList.contains('ig-cal-clear')) {
			field.value = '';
			fire(field);
			close();
		}
	});

	ui.timeInput.addEventListener('change', () => {
		const day = parse(field?.value);
		if (day) {
			pickDay(iso(day));
		}
	});

	addEventListener('focusin', event => {
		if (event.target.dataset?.igDate) {
			open(event.target);
		}
	});
	addEventListener('click', event => {
		if (event.target.dataset?.igDate && cal.hidden) {
			open(event.target);
		}
	});

	addEventListener('pointerdown', event => {
		if (field && !cal.contains(event.target) && event.target !== field) {
			close();
		}
	}, true);

	addEventListener('keydown', event => {
		if (!cal.hidden && event.key === 'Escape') {
			const input = field;
			close();
			input?.focus();
		}
	});

	markEditFields();

	addEventListener('resize', () => field && place());
	addEventListener('scroll', () => field && place(), true);
})();
