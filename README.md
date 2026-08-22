# Adminer Instrument

Adminer 5.4.1, restructured into an image of our own: smaller runtime, a curated
plugin set turned on by default, and a theme built for reading dense tables.

|                     | `adminer:latest` | `adminer-instrument` |
| ------------------- | ---------------- | -------------------- |
| unpacked rootfs     | 116 MB           | **35 MB**            |
| base                | `php:8.4-cli-alpine` (docker-php toolchain) | `alpine:3.22` + `php84` from apk |
| concurrency         | 1 request at a time | 4 workers (`PHP_CLI_SERVER_WORKERS`) |
| drivers             | MySQL, PgSQL (PDO), SQLite, dblib, ODBC | MySQL, PgSQL (**native + PDO**), SQLite |
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

Editing: `theme/_core.css` holds the structure, `theme/_tokens-dark.css` and
`theme/_tokens-light.css` hold the colours. The Dockerfile concatenates one
token file with the core into each variant, so the two can never drift apart.

## Layout

```
Dockerfile              multi-stage: upstream adminer -> alpine + php84
php.ini                 runtime settings (limits, opcache, cookie flags)
src/index.php           bootstrap: plugin loading, theme, login prefill
src/adminer.php         upstream 5.4.1, copied at build time
src/plugins/            upstream plugins, all available via ADMINER_PLUGINS
theme/_core.css         structure layer, colour-free
theme/_tokens-*.css     the two palettes
```

`src/index.php` is the only application code here. It runs before `adminer.php`,
attaches the theme through Adminer's `css()` hook, and instantiates plugins in
memory — which is why nothing in the image ever has to be writable.
