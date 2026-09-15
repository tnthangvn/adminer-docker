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
| databases behind SSH | not supported | a bastion on the login form, plus tunnels from env |
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
| `ADMINER_SSH_UI` | `on` | `off` removes the tunnel card from the login page |
| `ADMINER_SSH_CONFIG_DIR` | `/ssh` | Where a mounted `~/.ssh` is read from |
| `ADMINER_SSH_PROFILES` | `/data/tunnels.json` | Where saved tunnels are kept |
| `ADMINER_SSH_TUNNELS` | — | Tunnels opened at startup — see below |
| `ADMINER_SSH_KEY` | — | Default private key for those startup tunnels |
| `ADMINER_SSH_KNOWN_HOSTS` | — | Host keys to pin; without it, first key seen is trusted |
| `ADMINER_SSH_IDLE` | `1800` | Seconds before an unused login-form tunnel is closed |
| `ADMINER_SSH_TIMEOUT` | `10` | Seconds to wait for a bastion to answer |
| `ADMINER_SSH_OPTS` | — | Extra `ssh -o` arguments for the startup tunnels |
| `ADMINER_SSH_RETRY` | `5` | Seconds before a dropped startup tunnel is dialled again |
| `ADMINER_SSH_WAIT` | `15` | Seconds to wait for the startup tunnels before serving |

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

## Databases behind SSH

Adminer has no SSH of its own, and no PHP driver can be handed a tunnelled
stream — `mysqli` and `libpq` each open their own socket, and neither will take
one you already have. So the tunnel is a real `ssh -L` process living beside the
application: the login form asks for a bastion, the tunnel is opened before the
login is attempted, and Adminer connects to the local end of it without knowing
anything happened.

### The tunnel card

Beside the login form is a card that manages tunnels. It lists what you have
saved, opens and closes them, and puts the local address of the one you just
opened straight into the Server field — so logging in is the next thing you do
and nothing has to be copied by hand.

| Field | What goes in it |
| --- | --- |
| Name | what to call it in the list; made up from the rest if left blank |
| Bastion | a `Host` from your `~/.ssh/config`, or `user@host`, or `host:port` |
| SSH key / Password | which way to get in — see below |
| Key | which private key, when there is more than one; blank lets the config choose |
| SSH username | the account on the bastion |
| passphrase / password | whichever the choice above calls for. **Never saved** |
| Database host | the database **as the bastion sees it** — usually `localhost` |
| Database port | its port over there |
| Local port | which port to take here; blank lets the kernel pick |

**SSH key or Password is asked outright, not worked out.** The two are told
apart by the prompt `ssh` writes to a tty — `Enter passphrase` for a key,
`Password:` for an account — and nothing that answers that prompt on your
behalf can tell which one is coming before it arrives. Guessing meant a wrong
guess sat in front of a prompt that never came until a timeout cut it off. One
radio button removes the guess: the passphrase prompt is watched for in key
mode, the password prompt in password mode, and password mode also turns key
authentication off so a key lying around cannot answer for you — succeeding for
the wrong reason, and going on doing so until the day the key is gone.

A key that needs a passphrase says so before connecting rather than coming back
as `Permission denied (publickey)`.

So for a database in Docker on a machine you reach as `rv-dev`:

```
Bastion          rv-dev
Database host    localhost
Database port    5437
```

**Save** keeps it; **Open** brings it up; **Use** points the login form at one
that is already up; **Close** takes it down; **Edit** and **Delete** do what
they say. A tunnel opened without being saved is listed too, as "Not saved" —
a tunnel holding a port is a thing that exists, and leaving it off the list is
how ports go missing.

Under the tunnels, every `Host` in a mounted `~/.ssh/config` is listed as
somewhere to tunnel through — the same list your shell and your editor show
you, because it is the same file. **Tunnel** on one of them fills in the
bastion and leaves you the database and the port, which is the half your config
does not know. They are listed in full rather than filtered down to the ones
that look like bastions: a `Host` that only ever serves git today is still a
machine with a port you might want tomorrow.

Opening a tunnel and logging in are deliberately two acts rather than one. It
is the shape the job already had — `ssh -L` in one terminal, Adminer pointed at
127.0.0.1 in the other — and keeping it is what makes a tunnel worth having:
one tunnel serves as many logins, databases and sessions as you point at it,
and a mistyped database password costs a retry rather than a reconnection.

Below 860px wide the card folds back inside the login card, above the fields.

### Where the saved tunnels live

In `/data/tunnels.json`, which is a named volume in `docker-compose.yml`, so
they survive `up --build`. Leave that volume out and the card still opens
tunnels — it just stops offering to save them, and says so.

What is **not** in that file is any secret. A saved tunnel names a bastion, a
database, a port, and which way it authenticates — never the passphrase or the
password, which are typed each time. Writing a bastion password to a volume to
save that typing is a bad trade, and the card does not offer it: opening a saved
password tunnel asks for the password again, every time.

The file is shared by everyone who can reach the page, the same way
`~/.ssh/config` is shared by everyone who can read it. The tunnels themselves
are not: each browser opens, keeps alive and closes its own. Move it with
`ADMINER_SSH_PROFILES`.

### Using the ~/.ssh you already have

Mount your own `~/.ssh` and the SSH username, port and key all become optional
— they come from your config, the same way they would from a shell:

```yaml
volumes:
  - ${HOME}/.ssh:/ssh:ro
```

**Rebuild as yourself for this to work.** A private key is `0600` and belongs
to you, so a container running as a service account cannot open it — and `ssh`
refuses to start at all for a uid with no entry in `/etc/passwd`, which rules
out a plain `user:` override. The uid is therefore a build argument:

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d --build
```

Without it the card says which files it could not read, rather than letting it
surface later as `no such identity` for a key that is plainly right there.

Every `Host` in it is then listed in the card, and the Bastion field takes an
alias directly:

```
Host prod-bastion
  HostName 10.0.0.9
  User deploy
  Port 2222
  IdentityFile ~/.ssh/id_rsa
```

Press **Tunnel** beside `prod-bastion`, give the database host and port, and
open it. A host your config does not name still works — type it into Bastion,
and the mounted keys are tried for it too.

The **Key** dropdown lists the private keys it staged, for when the bastion is
not one your config names, or when it names the wrong key. Leave it blank and
the config decides, as it would from a shell.

Two things have to happen for a mounted config to work at all, and neither is
obvious enough to leave to chance:

- **The files are staged into tmpfs at `0600`.** `ssh` refuses a private key it
  believes anyone can read, and a bind mount carries the host's permissions and
  the host's owner — so a perfectly good `~/.ssh` mounted straight in is
  rejected key by key. The copies also give ssh somewhere to append a host key,
  which a read-only mount is not.
- **The config is passed with `-F`, and its `~/` is rewritten.** ssh finds the
  per-user config through `getpwuid()`, not `$HOME`, and expands `~` the same
  way — so a mounted config would simply be ignored, and its
  `IdentityFile ~/.ssh/id_rsa` would point at the image's read-only `/app` and
  fail with `no such identity`.

`ADMINER_SSH_CONFIG_DIR` moves the mount point if `/ssh` is inconvenient.

### Once you are in

The tunnel's local port is what ends up in the URL — the address bar reads
`?pgsql=127.0.0.1:13042`. A few consequences worth knowing:

- **The same bastion and database always reuse the same tunnel**, so a
  bookmarked URL still means something an hour later.
- **A dropped tunnel is reopened on the next page load.** Reboot the bastion
  and you refresh; you do not log in again.
- **Logging out leaves it open**, because the tunnel was never part of the
  login. Close it from the card, or leave it: a tunnel nobody has used for
  `ADMINER_SSH_IDLE` seconds (30 minutes by default) is swept away.
- **A tunnel that will not open says why** — `Permission denied`,
  `Could not resolve hostname`, `Wrong SSH password or key passphrase`. That
  is the other half of separating the two steps: an SSH failure reads as an
  SSH failure, instead of surfacing later as `connection refused` from a
  database that was never contacted.

### A fixed bastion, opened at startup

For a bastion everyone uses, `ADMINER_SSH_TUNNELS` declares tunnels that are
opened before the web server starts and supervised for the life of the
container. They show up as suggestions on the Server field, and the
healthcheck covers them, so `docker ps` stops claiming health this container
does not have.

```yaml
environment:
  ADMINER_SSH_TUNNELS: |
    prod=13306:10.0.0.5:3306:deploy@bastion.example.com
    stage=15432:db.internal:5432:ubuntu@1.2.3.4:2222|key=/run/secrets/stage_key
  ADMINER_SSH_KEY: /run/secrets/ssh_key
volumes:
  - ./ssh/id_ed25519:/run/secrets/ssh_key:ro
```

One per line, commas also work:

```
label=<local_port>:<db_host>:<db_port>:<ssh_user>@<ssh_host>[:<ssh_port>][|opt=value…]
```

The SSH user and port may be left out, in which case a mounted `~/.ssh/config`
supplies them — `local=13307:localhost:3306:prod-bastion` is a whole tunnel.

Per-tunnel options after a `|`: `key=<path>` for a key other than
`ADMINER_SSH_KEY`, and `pass=<VAR>` — the **name** of an environment variable
holding the password, so the secret stays out of the spec string, out of
`docker inspect`, and out of the log. The label is what the login page shows;
omit it and the local port is used. `db_host` is resolved on the bastion, and
every local port binds to `127.0.0.1` inside the container and is published
nowhere. Leave `ADMINER_SSH_TUNNELS` empty and no ssh runs at startup at all.

### What it does about the things that go wrong

**A key mounted from the host.** `ssh` rejects a private key it thinks anyone
can read, and a bind-mounted file carries the host's permissions and the host's
owner — which is why `-v ./id_ed25519:…:ro` normally ends in `UNPROTECTED
PRIVATE KEY FILE`. Keys are copied into tmpfs at `0600` before use, so it works
whatever the host thinks. The copies live in RAM and die with the container.

**A bastion that goes away.** Startup tunnels are supervised: `ssh` runs in the
foreground and is redialled `ADMINER_SSH_RETRY` seconds after it exits.
Login-form tunnels are reopened on the next request that needs them. Keepalives
every 10s are what make a dead peer *count* as gone — otherwise ssh would sit
there holding a local port open and accepting connections it could no longer
forward.

**A bastion nobody has vouched for.** A `known_hosts` staged from your own
`~/.ssh` is used strictly — those are host keys you have already met. With
nothing to go on, the first key offered is accepted and a warning is logged:
enough to get going, not enough to notice a man in the middle on that first
connection. Mounting your `~/.ssh`, or pointing `ADMINER_SSH_KNOWN_HOSTS` at a
file, makes host keys binding.

**A prompt nobody is going to answer.** `sshpass` watches for one prompt word;
in front of any other it waits, ssh waits with it, and `ConnectTimeout` was
satisfied long ago. The card asking which prompt to expect is what keeps this
from happening; the hard timeout on every attempt is what keeps it from
mattering when something else asks something unexpected.

### What this trusts

Anyone who can reach the login form can now make this container open an SSH
connection to a host of their choosing, with credentials of their choosing.
That is the same trust Adminer already extends — its login form will connect to
any database host you type — but the blast radius is larger, so it is worth
saying out loud rather than discovering.

**Mounting `~/.ssh` puts every key in it behind the login page.** Not just the
bastion's: anyone who can reach the form can reach anything those keys open,
without needing to know a passphrase for the ones that have none. Mount a
directory holding only the keys this is meant to use, rather than your whole
`~/.ssh`, wherever that distinction matters.

Credentials for a live tunnel are held in tmpfs, `0600` in a `0700` directory
owned by the runtime user, because reopening a dropped tunnel means having them;
they die with the container. A tunnel is reused, kept alive and closed only for
the browser that opened it, but a port that is currently up is still a port on
the loopback: someone else on the same instance who guesses it could connect
through it, if they also had the database's own credentials. Where that matters,
`ADMINER_SSH_UI=off` removes the form fields and the on-demand path entirely,
leaving only the tunnels declared in the environment.

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

A filled value box carries a clear cross, the one `type=search` is supposed to
draw and the palette swallows. Dropping a value that came back with the page
re-runs the query without it, because removing a filter is the only reason to
clear it.

## Filter from the column header

The search form sits above the grid and asks for the column again, even though
you got there by looking at one. Click a column header and the panel that opens
asks the two questions left — which test, which value — and submits: the box is
typed like the search form's own, so a timestamp opens the calendar. The panel
keeps `↑`/`↓` for sorting, the header's hover arrow still sorts on its own, and
a Ctrl- or Shift-click follows the sort link as before.

The whole cell opens it, not just the name: a bare click on a header row is
Adminer's shortcut for ticking every row in the page, which is never what
someone aiming at a column meant.

## Column picker

Limiting the columns means filling one dropdown per column you want to keep, in
a fieldset that starts collapsed. `columns` in the SELECT legend opens the same
thing as a checklist — every column of the table, its type beside it, a filter
box for wide tables. Ticking everything is plain `SELECT *`, so it submits with
the column list empty.

## Redis

Upstream publishes a Redis driver, but only from 6.0.x — the 5.4.1 download has
no such file. It talks RESP over `fsockopen` and asks nothing of PHP, so it runs
against this core unchanged and ships here: keys list as rows with their type
and value, one "table" per database number.

Note that Redis 7 with a plain `requirepass` still wants a username, and it is
`default`. Leaving it blank gets you `WRONGPASS`.

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
theme/select.*          row inspector, typed search, header filter, column picker
theme/enums.*           PostgreSQL enum labels
theme/combo.*           searchable selects, on every page
theme/datepicker.*      the calendar and its range shortcuts
```

`src/index.php` is the only application code here. It runs before `adminer.php`,
attaches the theme through Adminer's `css()` hook, and instantiates plugins in
memory — which is why nothing in the image ever has to be writable.
