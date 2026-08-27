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
				input.autocomplete = 'off';   // else Chrome's history covers the calendar
				input.spellcheck = false;
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
			<div class="ig-cal-body">
				<div class="ig-cal-calendar">
					<div class="ig-cal-week">${WEEKDAYS.map(d => `<span>${d}</span>`).join('')}</div>
					<div class="ig-cal-grid"></div>
				</div>
				<div class="ig-cal-time" hidden>
					<div class="ig-cal-wheels">
						<div class="ig-cal-wheel ig-cal-hh" aria-label="hour"></div>
						<div class="ig-cal-wheel ig-cal-mm" aria-label="minute"></div>
						<div class="ig-cal-wheel ig-cal-ss" aria-label="second"></div>
					</div>
				</div>
			</div>
			<footer>
				<span class="ig-cal-range" hidden></span>
				<button type="button" class="ig-cal-apply" hidden>Apply</button>
				<button type="button" class="ig-cal-clear">clear</button>
			</footer>
		</div>`;
	document.body.append(cal);

	const ui = {
		shortcuts: cal.querySelector('.ig-cal-shortcuts'),
		range: cal.querySelector('.ig-cal-range'),
		apply: cal.querySelector('.ig-cal-apply'),
		month: cal.querySelector('.ig-cal-month'),
		grid: cal.querySelector('.ig-cal-grid'),
		time: cal.querySelector('.ig-cal-time'),
	};

	/* The time control is three iOS-style scroll wheels: a column snaps to whole
	   values only, so what it reads back is always legal and there is no
	   locale-bound native time field to fight. A wheel is a scroller whose ticks
	   snap to centre; two half-height spacers let the first and last tick reach
	   it, so tick i sits at scrollTop = i · WHEEL_TICK. */
	const WHEEL_TICK = 24;
	const wheels = {
		hh: cal.querySelector('.ig-cal-hh'),
		mm: cal.querySelector('.ig-cal-mm'),
		ss: cal.querySelector('.ig-cal-ss'),
	};

	function buildWheel(el, n) {
		const ticks = Array.from({ length: n }, (_, i) => `<div class="ig-cal-tick" data-i="${i}">${pad(i)}</div>`);
		el.innerHTML = `<div class="ig-cal-pad"></div>${ticks.join('')}<div class="ig-cal-pad"></div>`;
		el.dataset.n = n;
	}
	buildWheel(wheels.hh, 24);
	buildWheel(wheels.mm, 60);
	buildWheel(wheels.ss, 60);

	const wheelIndex = el => Math.max(0, Math.min(+el.dataset.n - 1, Math.round(el.scrollTop / WHEEL_TICK)));
	const eachWheel = fn => { fn(wheels.hh); fn(wheels.mm); fn(wheels.ss); };

	/** Centre tick i, and mark it active so the middle number reads brighter. */
	function setWheel(el, i, smooth) {
		const n = +el.dataset.n;
		const clamped = Math.max(0, Math.min(n - 1, i));
		el.scrollTo({ top: clamped * WHEEL_TICK, behavior: smooth ? 'smooth' : 'auto' });
		markActive(el);
	}
	function markActive(el) {
		const at = wheelIndex(el);
		el.querySelectorAll('.ig-cal-tick').forEach(t => t.classList.toggle('is-active', +t.dataset.i === at));
	}

	const getTime = () => `${pad(wheelIndex(wheels.hh))}:${pad(wheelIndex(wheels.mm))}:${pad(wheelIndex(wheels.ss))}`;

	// Positioning the wheels emits scroll events too; hold off committing until
	// they have flushed, so opening the panel does not rewrite an untouched field.
	let programmatic = false;
	function setTime(value) {
		const [h = '0', m = '0', s = '0'] = (value || '').split(':');
		programmatic = true;
		setWheel(wheels.hh, +h || 0);
		setWheel(wheels.mm, +m || 0);
		setWheel(wheels.ss, +s || 0);
		requestAnimationFrame(() => requestAnimationFrame(() => { programmatic = false; }));
	}

	let field = null;      // the input being edited
	let cursor = today();  // month on show
	let from = null;       // range start, once picked
	let to = null;         // range end, once picked
	let hover = null;      // the day under the pointer while a range is open

	/** A range needs two conditions, so it only makes sense in the search form. */
	const ranged = () => !!search?.contains(field);

	function open(input) {
		field = input;
		cursor = parse(input.value) || today();

		from = parse(input.value);
		to = null;
		hover = null;

		const withTime = input.dataset.igDate === 'datetime';
		ui.shortcuts.hidden = !ranged();
		ui.range.hidden = !ranged();
		ui.apply.hidden = !ranged();
		ui.time.hidden = !withTime;
		label();

		cal.hidden = false;
		draw();
		// A timestamp column needs a time to be a timestamp. Show the midnight
		// about to be written rather than a bare date. Positioned after the panel
		// is shown — a wheel cannot scroll while it is still display:none.
		setTime(withTime ? (timeOf(input.value) || '00:00:00') : '00:00:00');
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
			if (chosen && +day === +chosen && !ranged()) {
				classes.push('ig-cal-chosen');
			}
			if (ranged()) {
				const close = to || hover;
				if (from && +day === +from) {
					classes.push('ig-cal-chosen', 'ig-cal-from');
				}
				if (close && +day === +close && from && +close >= +from) {
					classes.push('ig-cal-chosen', 'ig-cal-to');
				}
				if (from && close && +day > +from && +day < +close) {
					classes.push('ig-cal-between');
				}
			}
			cells.push(`<button type="button" class="${classes.join(' ')}" data-date="${iso(day)}">${day.getDate()}</button>`);
		}
		ui.grid.innerHTML = cells.join('');
	}

	function label() {
		if (!ranged()) {
			return;
		}
		ui.range.textContent = from
			? (to ? `${iso(from)} → ${iso(to)}` : `${iso(from)} → …`)
			: 'pick a day, or two';
		ui.apply.disabled = !from;
	}

	/**
	 * First click opens a range, second closes it. Clicking before the start
	 * means you changed your mind about where it begins.
	 */
	function pickInRange(day) {
		if (!from || to || +day < +from) {
			from = day;
			to = null;
		} else {
			to = day;
		}
		hover = null;
		label();
		draw();
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

	/** The value to write for a day: with the time of day when the column has one. */
	function stamp(isoDate) {
		if (ui.time.hidden) {
			return isoDate;
		}

		return `${isoDate} ${getTime()}`;
	}

	function pickDay(isoDate) {
		field.value = stamp(isoDate);
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

		field.value = stamp(iso(start));
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
			val.value = stamp(iso(end));
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
			const day = parse(button.dataset.date);
			ranged() ? pickInRange(day) : pickDay(button.dataset.date);
		} else if (button.classList.contains('ig-cal-apply')) {
			if (from && to) {
				applyRange(from, new Date(to.getFullYear(), to.getMonth(), to.getDate() + 1));
			} else if (from) {
				pickDay(iso(from));       // one day is not a range; leave the operator alone
				close();
			}
		} else if (button.dataset.step) {
			cursor = new Date(cursor.getFullYear(), cursor.getMonth() + Number(button.dataset.step), 1);
			draw();
		} else if (button.dataset.range) {
			shortcut(button.dataset.range);
		} else if (button.classList.contains('ig-cal-clear')) {
			field.value = '';
			from = to = hover = null;
			fire(field);
			close();
		}
	});

	ui.grid.addEventListener('mouseover', event => {
		const day = event.target.closest('.ig-cal-day');
		if (ranged() && from && !to && day) {
			hover = parse(day.dataset.date);
			label();
			draw();
		}
	});

	/* A wheel fires a stream of scroll events; write the field back once it
	   settles, and keep the centred number highlighted while it turns. */
	let settle;
	function commitTime() {
		const day = parse(field?.value);
		if (day) {
			pickDay(iso(day));
		}
	}
	eachWheel(el => el.addEventListener('scroll', () => {
		markActive(el);
		if (programmatic) {
			return;
		}
		clearTimeout(settle);
		settle = setTimeout(commitTime, 140);
	}));

	// Tapping a number rolls that wheel to it; the scroll it starts commits.
	cal.querySelector('.ig-cal-wheels').addEventListener('click', event => {
		const tick = event.target.closest('.ig-cal-tick');
		if (tick) {
			setWheel(tick.parentElement, +tick.dataset.i, true);
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
