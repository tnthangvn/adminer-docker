# Adminer Instrument

Adminer 5.4.1, restructured into an image of our own: smaller runtime, a curated
plugin set turned on by default, and a theme built for reading dense tables.

|                     | `adminer:latest` | `adminer-instrument` |
| ------------------- | ---------------- | -------------------- |
| unpacked rootfs     | 116 MB           | **35 MB**            |
| base                | `php:8.4-cli-alpine` (docker-php toolchain) | `alpine:3.22` + `php84` from apk |
| concurrency         | 1 request at a time | 4 workers (`PHP_CLI_SERVER_WORKERS`) |
| drivers             | MySQL, PgSQL (PDO), SQLite, dblib, ODBC | MySQL, PgSQL (**native + PDO**), SQLite, **MongoDB** |
| plugins             | none, unless you set `ADMINER_PLUGINS` | 16 on by default |
| runtime writes      | symlinks + generated plugin files | none — runs `read_only: true` |
| `X-Powered-By`      | leaks the PHP version | off |

The PHP payload is copied from the official image at build time, so `adminer.php`
is byte-for-byte the upstream release — no forked application code to maintain.

## Run it

```bash
docker compose up -d --build
# or
docker build -t adminer-instrument .
docker run -d -p 9006:8080 adminer-instrument
```

## Configure

| Variable | Default | What it does |
| --- | --- | --- |
| `ADMINER_THEME` | `dark` | `dark`, `light`, `auto` (follows the OS), or `none` for stock Adminer |
| `ADMINER_DEFAULT_DRIVER` | — | Preselects the System field: `server` (MySQL), `pgsql`, `sqlite`, `oracle`, `mssql` |
| `ADMINER_DEFAULT_SERVER` | — | Prefills Server |
| `ADMINER_DEFAULT_USERNAME` | — | Prefills Username |
| `ADMINER_DEFAULT_DB` | — | Prefills Database |
| `ADMINER_PLUGINS` | see below | Replaces the default set entirely |
| `ADMINER_PLUGINS_ADD` | — | Adds to the default set |
| `ADMINER_PLUGINS_DISABLE` | — | Removes from the default set |
| `PHP_CLI_SERVER_WORKERS` | `4` | Worker processes for the built-in server |

The password is never prefilled.

Any plugin shipped with Adminer can be named — the files live in `/app/plugins`.
Plugins that need constructor arguments are skipped with a line in the log
rather than taking the page down.

### Plugins on by default

`tables-filter` `table-indexes-structure` `pretty-json-column` `edit-foreign`
`edit-textarea` `enum-option` `backward-keys` `foreign-system` `version-noverify`
`dump-json` `dump-xml` `dump-zip` `dump-bz2` `dump-date` `dump-alter`

Two upstream plugins are deliberately left out:

- **`row-numbers`** hooks `backwardKeys`, and the first non-null hook wins — it
  would silently switch off `backward-keys`.
- **`before-unload`** counts a browser-autofilled password as an edit, so the
  login page starts asking "leave site?". Add it back with
  `ADMINER_PLUGINS_ADD=before-unload` if you want it on the edit forms.

## The theme

One rule drives it: **values that came out of the database are monospace;
everything the application itself says is sans-serif.** Amber is spent in
exactly one place — the rail that marks where you are: hovered row, selected
row, the last breadcrumb segment, the primary button.

What that changes in practice:

- **The table list** loses the wall of repeated "select" links — the shortcut to
  the data is a mark, and long names fade out at the edge instead of being cut
  mid-glyph. The filter box sits on top of the list it filters, and the list
  scrolls on its own instead of pushing the language picker off screen.
- **The grid** drops zebra striping, which is noise at 200 rows and fights the
  type colours. Row separation is a hairline; the row you are on gets an amber
  rail, and so does every row you tick.
- **`NULL`** renders as a dim dotted token, so it can never be confused with an
  empty string or the literal text "NULL".
- **Column headers** stay lowercase — uppercasing `userId` would lie about the
  identifier.
- **The breadcrumb** is set like the path it is: monospace, dim segments, amber
  where you are standing.
- **The login page** stops being a stray two-column table in the top-left corner
  and becomes a centred card.
- **SQL highlighting** reuses the data spectrum, so a query and the rows it
  returns speak one colour language.

## Searchable selects

Past ten options a dropdown is a scroll hunt — "which column?" on a forty-column
table, the foreign-key pickers, the database list. Any select longer than that
gets a filter box: type to narrow, arrows to move, Enter to pick.

The native `<select>` stays in the DOM and stays authoritative. It is what the
form submits, and Adminer has already bound its own `onchange` handlers to it by
the time this runs, so the combobox only sets a value and fires `change` —
everything downstream behaves as if you had used the dropdown.

## Row inspector

Reading a row in the grid means fighting two things: every value is truncated to
TEXT LENGTH, and a wide table pushes the interesting columns off the right edge.

Click a row — which already ticks it, Adminer's own selection — and a panel
slides over the grid with the whole row: column, declared type, and the **full**
value, fetched from the edit form rather than read off the truncated cell. JSON
columns are pretty-printed.

Click a second row and both are in the panel, one collapsible section each,
because comparing two rows is most of why you went looking. `Copy JSON` gives
an object for one row and an array for several. `Edit` opens the row's own edit
page, or Adminer's bulk edit for a selection; `Clone` goes through the same
button the footer uses. Click any single field to copy just that value. `↑`/`↓`
move the selection a row at a time, `Esc` closes.

## Typed search

Adminer gives every column the same text box, so filtering on a timestamp means
typing `2026-07-24 09:30:00` by hand. The column types are already in the table
header, so the value field follows the column: a date picker for `date`, a
datetime picker for `timestamp` (the `T` is rewritten to a space on submit), a
number spinner for numerics, `true`/`false` for booleans, and the allowed labels
for an enum. The operator also stops defaulting to `LIKE %%` on types where it
makes no sense — until you pick one yourself, after which it is left alone.

## MongoDB

Adminer ships a MongoDB driver but leaves it switched off, and the official
image has no `mongodb` extension to run it with. This one has both, so
**MongoDB (alpha)** is in the System list out of the box: collections show up
as tables and documents as rows.

`ADMINER_DRIVERS` picks the set — the others in `plugins/drivers/` are
`clickhouse`, `elastic`, `firebird`, `imap` and `simpledb`, though each needs
its own PHP extension to actually connect.

## PostgreSQL enums

A PostgreSQL enum is a named type, so Adminer only ever sees the name: the
structure page prints `"EMPLOYMENT_TYPE"` and the edit form gives you a free
text box. One join against `pg_enum` fixes all three places — the structure page
lists the allowed labels, the edit form becomes a real dropdown, and the search
box offers the values.

## Schema walker

Adminer renders `?schema=` as one absolutely-positioned column — on this
database, 155 tables stacked into a `3097em` page with connector lines drawn
over the top. It is a list, not a diagram.

The walker replaces it with the view the question actually has: **one table in
focus, what it references on the left, what references it on the right.** Click
a neighbour to walk to it; the trail across the top is where you have been, with
the current table in amber. Foreign keys are drawn as curves that flow towards
the table being referenced, so direction is readable without arrowheads. Hover a
neighbour to light its key and dim the rest. `#table` in the URL is a deep link,
and `Adminer diagram` toggles the original back.

None of this costs a query — it is read back out of Adminer's own markup, where
`.references` already carries the related table in `title` and the column in its
`top` offset. Files: [`theme/schema.js`](theme/schema.js),
[`theme/schema.css`](theme/schema.css); the plugin that loads them on that one
page lives in `src/index.php`.

### Editing it

`theme/core.css` holds the structure, `theme/tokens-dark.css` and
`theme/tokens-light.css` hold the colours. They ship as separate stylesheets on
purpose — mount the directory and a save is live on the next refresh, with no
rebuild and no restart, because each file's cache key is its own mtime:

```bash
docker run -d -p 9006:8080 -v "$PWD/theme:/app/theme:ro" adminer-instrument
```

Rebuild only when you want the changes baked into the image.

## Layout

```
Dockerfile              multi-stage: upstream adminer -> alpine + php84
php.ini                 runtime settings (limits, opcache, cookie flags)
src/index.php           bootstrap: plugin loading, theme, login prefill
src/adminer.php         upstream 5.4.1, copied at build time
src/plugins/            upstream plugins, all available via ADMINER_PLUGINS
theme/core.css          structure layer, colour-free
theme/tokens-*.css      the two palettes
theme/schema.*          the schema walker
theme/select.*          row inspector and typed search
theme/enums.*           PostgreSQL enum labels
theme/combo.*           searchable selects, on every page
theme/datepicker.*      the calendar and its range shortcuts
```

`src/index.php` is the only application code here. It runs before `adminer.php`,
attaches the theme through Adminer's `css()` hook, and instantiates plugins in
memory — which is why nothing in the image ever has to be writable.
