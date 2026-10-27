<?php
/**
 * Adminer Instrument — container bootstrap.
 *
 * Everything here runs before adminer.php takes over:
 *
 *   1. loads the plugin set named by ADMINER_PLUGINS (+ADD, -DISABLE),
 *   2. attaches the Instrument theme through the css() hook, so no file in
 *      the image ever has to be written at runtime,
 *   3. prefills the login form from ADMINER_DEFAULT_* variables, offers the
 *      SSH tunnels opened by entrypoint.sh as Server suggestions, and adds the
 *      SSH block that opens one on demand (see ssh.php).
 *
 * Plugin classes extend \Adminer\Plugin, which only exists once adminer.php
 * has been parsed — so every declaration below sits inside build(), which
 * Adminer calls at exactly the right moment.
 */

namespace Instrument {

    /**
     * Plugins loaded when ADMINER_PLUGINS is unset. All of them ship in the
     * image, none of them fetch anything from the network.
     *
     * Two upstream plugins are deliberately absent:
     *   row-numbers   hooks backwardKeys, the first non-null hook wins, and it
     *                 would silently disable backward-keys;
     *   before-unload treats a browser-autofilled password as an edit, so the
     *                 login page starts asking "leave site?" — add it back with
     *                 ADMINER_PLUGINS_ADD if you want it on the edit forms.
     */
    const DEFAULT_PLUGINS = 'tables-filter table-indexes-structure pretty-json-column'
        . ' edit-foreign edit-textarea enum-option'
        . ' backward-keys foreign-system version-noverify'
        . ' dump-json dump-xml dump-zip dump-bz2 dump-date dump-alter';

    function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    /** @return list<string> plugin file names, in load order, deduplicated */
    function plugin_names(): array
    {
        $wanted = preg_split('~[\s,]+~', env('ADMINER_PLUGINS', DEFAULT_PLUGINS) . ' ' . env('ADMINER_PLUGINS_ADD'), -1, PREG_SPLIT_NO_EMPTY);
        $unwanted = preg_split('~[\s,]+~', env('ADMINER_PLUGINS_DISABLE'), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff(array_unique($wanted), $unwanted));
    }

    /**
     * Instantiates one plugin file. Returns null and warns to the error log
     * rather than taking the whole page down: a broken plugin should cost you
     * one feature, not your database console.
     *
     * @return list<object>
     */
    function load(string $name): array
    {
        if (basename($name) !== $name || !is_readable($file = __DIR__ . "/plugins/$name.php")) {
            error_log("adminer: no such plugin '$name'");

            return [];
        }

        $before = get_declared_classes();
        require_once $file;

        $instances = [];
        foreach (array_diff(get_declared_classes(), $before) as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
                error_log("adminer: plugin '$name' needs constructor arguments, skipping");
                continue;
            }
            $instances[] = new $class();
        }

        return $instances;
    }

    /**
     * Database drivers to load, beyond the four compiled into Adminer.
     * These are plain includes: a driver file registers itself with
     * add_driver() and declares its classes only for the driver in use, so
     * unlike a plugin there is nothing to instantiate.
     */
    const DEFAULT_DRIVERS = 'mongo redis';

    function drivers(): void
    {
        $wanted = preg_split('~[\s,]+~', env('ADMINER_DRIVERS', DEFAULT_DRIVERS), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($wanted as $name) {
            $file = __DIR__ . "/plugins/drivers/" . basename($name) . '.php';
            if (is_readable($file)) {
                require_once $file;
            } else {
                error_log("adminer: no such driver '$name'");
            }
        }
    }

    /**
     * SSH tunnels declared in ADMINER_SSH_TUNNELS, as label => "127.0.0.1:port".
     *
     * entrypoint.sh is what actually opens them; this reads the same variable
     * only so the login page can suggest the local ends. The format is
     * documented there — all that matters here is the label and the local
     * port, so a spec this cannot parse is skipped rather than reported: the
     * shell has already logged it.
     *
     * @return array<string, string>
     */
    function ssh_tunnels(): array
    {
        static $tunnels = null;
        if ($tunnels !== null) {
            return $tunnels;
        }

        $tunnels = [];
        foreach (preg_split('~[\s,]+~', env('ADMINER_SSH_TUNNELS'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $line = preg_replace('~#.*~', '', $line);
            [$spec] = explode('|', $line, 2);              // strip per-tunnel options
            $label = '';
            if (str_contains($spec, '=')) {
                [$label, $spec] = explode('=', $spec, 2);
            }

            $parts = explode(':', $spec);
            if (count($parts) < 4 || !ctype_digit($parts[0])) {
                continue;
            }

            $tunnels[$label !== '' ? $label : $parts[0]] = '127.0.0.1:' . $parts[0];
        }

        return $tunnels;
    }

    /**
     * Sends a single uploaded import file down the "From server" path.
     *
     * For an upload, Adminer reads the whole file into one string and, after
     * every statement, copies what is left of it — quadratic in the file
     * size: a 182 MB dump spent 45 of its 50 s of parsing in those copies.
     * The server-file path reads 100 kB at a time instead, so each copy is a
     * statement or so long. Setting webfile reroutes the upload there, and
     * importServerPath() points it at the uploaded file.
     *
     * The file is opened through compress.zlib://, which reads a gzip and a
     * plain file alike, so it is moved to "<tmp>.gz" — Adminer appends the .gz
     * itself when the bare path does not exist. Several files at once are
     * left to Adminer, which concatenates them.
     *
     * @return string|null the path to hand importServerPath(), once rerouted
     */
    function stream_upload(): ?string
    {
        static $path = false;
        if ($path !== false) {
            return $path;
        }
        $path = null;

        $file = $_FILES['sql_file'] ?? null;
        if (
            !isset($_GET['import']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($_POST['webfile'])
            || !is_array($file['tmp_name'] ?? null) || count($file['tmp_name']) !== 1
            || ($file['error'][0] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !extension_loaded('zlib')
        ) {
            return null;
        }

        $tmp = $file['tmp_name'][0];
        if (!move_uploaded_file($tmp, "$tmp.gz")) {
            return null;
        }
        // PHP only removes the upload from where it put it.
        register_shutdown_function(static fn() => @unlink("$tmp.gz"));

        $_POST['webfile'] = '1';

        return $path = $tmp;
    }

    function build(): \Adminer\Plugins
    {
        drivers();

        /**
         * Serves the Instrument theme, honouring ADMINER_THEME.
         *
         * The palette and the structure stay separate files rather than one
         * built bundle: mount ./theme over /app/theme and a save is live on the
         * next refresh, because the cache key is each file's own mtime.
         */
        final class Theme extends \Adminer\Plugin
        {
            /** @return array<string,string> stylesheet URL => colour scheme it applies to */
            public function css(): array
            {
                $mode = env('ADMINER_THEME', 'dark');
                if ($mode === 'none') {
                    return [];
                }

                $sheets = $mode === 'auto'
                    ? $this->sheet('tokens-light', 'light') + $this->sheet('tokens-dark', 'dark')
                    : $this->sheet('tokens-' . ($mode === 'light' ? 'light' : 'dark'), '');

                return $sheets + $this->sheet('core', '');   // tokens first, structure after
            }

            /** @return array<string,string> */
            private function sheet(string $name, string $scheme): array
            {
                $path = "theme/$name.css";
                $stamp = @filemtime(__DIR__ . "/$path") ?: 0;

                return ["$path?v=$stamp" => $scheme];
            }
        }

        /**
         * Loads the per-page enhancements: the schema walker, and the row
         * inspector plus typed search on select pages. Returns null so Adminer
         * still prints its own head — this only appends to it.
         */
        final class PageAssets extends \Adminer\Plugin
        {
            /** Page flag in $_GET => asset basename in theme/. */
            private const PAGES = [
                'schema' => ['schema'],
                'select' => ['select', 'datepicker'],
                'edit'   => ['datepicker'],
            ];

            public function head($dark = null): ?bool
            {
                $assets = ['combo'];          // every page has a long select somewhere
                foreach (self::PAGES as $flag => $page) {
                    if (isset($_GET[$flag])) {
                        array_push($assets, ...$page);
                    }
                }

                if ($this->columnTypes()) {
                    echo \Adminer\script('window.igFields = ' . json_encode($this->columnTypes()) . ';');
                }
                if ($this->enumColumns()) {
                    echo \Adminer\script('window.igEnums = ' . json_encode($this->enumColumns()) . ';');
                    $assets[] = 'enums';
                }

                foreach ($assets as $asset) {
                    $stamp = @filemtime(__DIR__ . "/theme/$asset.js") ?: 0;
                    echo '<link rel="stylesheet" href="theme/' . $asset . '.css?v=' . $stamp . '">' . "\n";
                    echo \Adminer\script_src("theme/$asset.js?v=$stamp", true);
                }

                return null;
            }

            /**
             * Column => declared type, for the table on screen.
             *
             * The select page carries types in its header cells, but the edit
             * form does not — it renders a bare text box whatever the column
             * holds. This is what tells the date picker which fields are dates.
             *
             * @return array<string, string>
             */
            private function columnTypes(): array
            {
                static $types = null;
                if ($types !== null) {
                    return $types;
                }

                $types = [];
                foreach ($this->tableFields() as $name => $field) {
                    $types[$name] = trim((string) $field['type'], '"');
                }

                return $types;
            }

            /** @return array<string, array> the current table's fields, or none */
            private function tableFields(): array
            {
                static $fields = null;
                if ($fields !== null) {
                    return $fields;
                }

                $fields = [];
                $table = $_GET['table'] ?? $_GET['select'] ?? $_GET['edit'] ?? '';
                if ($table === '') {
                    return $fields;
                }

                try {
                    $fields = \Adminer\fields($table);
                } catch (\Throwable $e) {
                    error_log('adminer: field lookup failed: ' . $e->getMessage());
                }

                return $fields;
            }

            /**
             * Column => allowed labels, for the table on screen.
             *
             * PostgreSQL enums are named types, so Adminer reports the column
             * as `"EMPLOYMENT_TYPE"` and never learns what may go in it — the
             * edit form gives you a free text box and the structure page shows
             * a type nobody can expand. The labels are one join away.
             *
             * @return array<string, list<string>>
             */
            private function enumColumns(): array
            {
                static $columns = null;
                if ($columns !== null) {
                    return $columns;
                }

                $columns = [];
                if (\Adminer\DRIVER !== 'pgsql' || !$this->tableFields()) {
                    return $columns;
                }

                try {
                    $labels = [];
                    $rows = \Adminer\get_rows(
                        'SELECT t.typname, e.enumlabel FROM pg_type t'
                        . ' JOIN pg_enum e ON e.enumtypid = t.oid'
                        . ' ORDER BY t.typname, e.enumsortorder', null, '');
                    foreach ($rows as $row) {
                        $labels[$row['typname']][] = $row['enumlabel'];
                    }
                    if (!$labels) {
                        return $columns;
                    }

                    foreach ($this->columnTypes() as $name => $type) {
                        if (isset($labels[$type])) {
                            $columns[$name] = $labels[$type];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('adminer: enum lookup failed: ' . $e->getMessage());
                }

                return $columns;
            }
        }

        /**
         * Prefills the login form from ADMINER_DEFAULT_* variables, and hangs a
         * <datalist> of SSH tunnel endpoints off the Server field.
         *
         * Both live in one plugin on purpose: loginFormField is not one of the
         * hooks Adminer merges, so the first plugin to return a non-null value
         * for a field is the only one that gets to touch it. Two plugins here
         * would mean the second silently doing nothing.
         */
        final class LoginDefaults extends \Adminer\Plugin
        {
            /** @var array<string,string> */
            private array $values;

            /** @var array<string,string> tunnel label => 127.0.0.1:port */
            private array $tunnels;

            /** @var array<string,string> auth[] from a login the SSH step rejected */
            private array $submitted;

            public function __construct()
            {
                $this->values = array_filter([
                    'driver'   => env('ADMINER_DEFAULT_DRIVER'),
                    'server'   => env('ADMINER_DEFAULT_SERVER'),
                    'username' => env('ADMINER_DEFAULT_USERNAME'),
                    'db'       => env('ADMINER_DEFAULT_DB'),
                ]);
                $this->tunnels = ssh_tunnels();

                // A tunnel that was just opened, or picked from the card, is
                // almost certainly the server about to be logged in to — so
                // the Server field is filled with its local address rather
                // than leaving it to be copied by hand.
                $port = \Instrument\Ssh\state()['opened'];
                $this->submitted = $port > 0 ? ['server' => '127.0.0.1:' . $port] : [];
            }

            public function loginFormField(string $name, string $heading, string $field): ?string
            {
                $original = $field;

                if ($name === 'server' && $this->tunnels) {
                    $field = $this->withTunnels($field);
                }

                $value = $this->submitted[$name] ?? $this->values[$name] ?? null;
                if ($value !== null && $value !== '' && (!isset($_POST['auth']) || isset($this->submitted[$name]))) {
                    $field = $this->prefill($name, $value, $field);
                }

                return $field === $original
                    ? null                           // let Adminer render it
                    : $heading . $field . "\n";
            }

            /**
             * Lists the tunnels as Server suggestions.
             *
             * A <datalist> rather than a <select>: the field stays typeable, so
             * a database that needs no bastion is still one keystroke away.
             */
            private function withTunnels(string $field): string
            {
                $list = '';
                foreach ($this->tunnels as $label => $endpoint) {
                    $list .= '<option value="' . htmlspecialchars($endpoint, ENT_QUOTES) . '">'
                        . htmlspecialchars($label, ENT_QUOTES) . ' (SSH)</option>';
                }

                return str_replace(
                    '<input name="auth[server]"',
                    '<input name="auth[server]" list="instrument-tunnels"',
                    $field
                ) . '<datalist id="instrument-tunnels">' . $list . '</datalist>';
            }

            private function prefill(string $name, string $value, string $field): string
            {
                $quoted = htmlspecialchars($value, ENT_QUOTES);

                if ($name === 'driver') {                    // a <select>
                    $field = preg_replace('~ selected(="[^"]*")?~', '', $field);

                    return str_replace("value=\"$quoted\"", "value=\"$quoted\" selected", $field);
                }

                if (str_contains($field, 'value=""')) {      // an <input>
                    return str_replace('value=""', "value=\"$quoted\"", $field);
                }

                return preg_replace('~(name="auth\[' . preg_quote($name, '~') . '\]")~', "$1 value=\"$quoted\"", $field, 1);
            }
        }

        /**
         * The SSH tunnel card, above the login form.
         *
         * It is a card and not a row of the login table because opening a
         * tunnel is not part of logging in: one tunnel serves any number of
         * logins, databases and sessions, and a mistyped database password
         * should cost a retry rather than a reconnection.
         *
         * Adminer prints exactly one <form> on this page, and loginForm() runs
         * inside it — so the card shares that form and submits through its own
         * named buttons. No nested form, no JavaScript, and the browser keeps
         * doing what browsers do with a submit button.
         */
        final class SshCard extends \Adminer\Plugin
        {
            public function loginForm(): ?bool
            {
                if (!\Instrument\Ssh\enabled()) {
                    return null;
                }

                $state = \Instrument\Ssh\state();
                $open = \Instrument\Ssh\tunnels();

                echo "<div class='ig-tunnel'>\n<h3>SSH tunnel</h3>\n";
                if ($state['error'] !== '') {
                    echo "<div class='error'>" . \Adminer\h($state['error']) . "</div>\n";
                }
                if ($state['note'] !== '') {
                    echo "<p class='ig-note'>" . \Adminer\h($state['note']) . "</p>\n";
                }
                // Said once, up front: a key the container cannot read fails
                // later as "no such identity", which points at the wrong thing.
                if (($staging = \Instrument\Ssh\staging_error()) !== '') {
                    echo "<div class='error'>" . \Adminer\h($staging) . "</div>\n";
                }
                echo $this->list(\Instrument\Ssh\profiles(), $open, $state['opened']);
                echo $this->fromConfig(\Instrument\Ssh\config_hosts());
                echo $this->form($state['form'], $state['editing']);
                echo "</div>\n";
                echo $this->script();

                return null;                     // Adminer still prints its form
            }

            /**
             * The saved tunnels, and anything open that was never saved.
             *
             * One row per tunnel, whether it is running or not, because "which
             * tunnels are there" and "which are up right now" are the same
             * question to whoever is looking — the address only appears on the
             * ones that have one.
             *
             * @param list<array<string, mixed>>                     $saved
             * @param array<int, array{via: string, target: string}> $open
             */
            private function list(array $saved, array $open, int $current): string
            {
                $rows = '';
                foreach ($saved as $tunnel) {
                    $port = $this->portOf($tunnel, $open);
                    $rows .= $this->row(
                        (string) $tunnel['name'],
                        $tunnel['host'] . ':' . $tunnel['port'] . ' via ' . $tunnel['ssh'],
                        $port,
                        $port === $current,
                        (string) $tunnel['id']
                    );
                    unset($open[$port]);
                }

                // Opened from the form without being saved. Listed all the
                // same: a tunnel holding a port is a thing that exists, and
                // leaving it off the list is how ports go missing.
                foreach ($open as $port => $tunnel) {
                    $rows .= $this->row('Not saved', $tunnel['target'] . ' via ' . $tunnel['via'], $port, $port === $current, '');
                }

                return $rows === '' ? '' : "<ul class='ig-open'>\n" . $rows . "</ul>\n";
            }

            /**
             * Every Host in the mounted ~/.ssh/config, as somewhere to tunnel
             * through.
             *
             * The config is the list of machines you already reach, so it is
             * the list this card starts from — the same one your shell, your
             * editor and everything else that reads it show you. Picking one
             * fills in the bastion; a tunnel still needs a database and a port,
             * and only you know which.
             *
             * Listed in full, patterns aside, rather than filtered down to the
             * ones that look like bastions: a `Host` that is only ever used for
             * git today is still a machine with a port you might want tomorrow,
             * and guessing which is which would be wrong at exactly the moment
             * it mattered.
             *
             * @param list<string> $hosts
             */
            private function fromConfig(array $hosts): string
            {
                if (!$hosts) {
                    return '';
                }

                $rows = '';
                foreach ($hosts as $host) {
                    $rows .= "<li><span>" . \Adminer\h($host) . "</span>"
                        . "<button type='submit' name='tunnel[from]' value='" . \Adminer\h($host) . "'"
                        . " class='ig-quiet'>Tunnel</button></li>\n";
                }

                return "<p class='ig-from'>~/.ssh/config</p>\n<ul class='ig-hosts'>\n" . $rows . "</ul>\n";
            }

            /** The local port a saved tunnel is currently on, or 0. */
            private function portOf(array $tunnel, array $open): int
            {
                $target = $tunnel['host'] . ':' . $tunnel['port'];
                foreach ($open as $port => $running) {
                    if ($running['target'] === $target && str_contains($running['via'], (string) $tunnel['ssh'])) {
                        return (int) $port;
                    }
                }

                return 0;
            }

            private function row(string $name, string $target, int $port, bool $current, string $id): string
            {
                $buttons = $port > 0
                    ? "<button type='submit' name='tunnel[use]' value='$port'>Use</button>"
                        . "<button type='submit' name='tunnel[close]' value='$port' class='ig-quiet'>Close</button>"
                    : "<button type='submit' name='tunnel[start]' value='" . \Adminer\h($id) . "'>Open</button>";

                if ($id !== '') {
                    $buttons .= "<button type='submit' name='tunnel[edit]' value='" . \Adminer\h($id) . "' class='ig-quiet'>Edit</button>"
                        . "<button type='submit' name='tunnel[delete]' value='" . \Adminer\h($id) . "' class='ig-quiet'>Delete</button>";
                }

                return "<li" . ($current ? " class='ig-current'" : '') . ">"
                    . "<strong>" . \Adminer\h($name) . "</strong>"
                    . ($port > 0 ? "<code>127.0.0.1:$port</code>" : '')
                    . "<span>" . \Adminer\h($target) . "</span>"
                    . "<div class='ig-acts'>" . $buttons . "</div>"
                    . "</li>\n";
            }

            /**
             * The fields that describe a tunnel, for adding or editing one.
             *
             * With a ~/.ssh mounted, the username and key are optional — ssh
             * reads them from the config you already keep — so the labels say
             * so rather than leaving you to find out by submitting. The secret
             * is never saved with the rest: a passphrase belongs to the person
             * typing it, not to a file in a volume.
             *
             * @param array<string, mixed> $was
             */
            private function form(array $was, string $editing): string
            {
                $hosts = \Instrument\Ssh\config_hosts();
                $keys = \Instrument\Ssh\private_keys();
                $mode = ($was['auth'] ?? '') === 'password' || (!$keys && !$hosts) ? 'password' : 'key';

                $fields = ($editing !== ''
                        ? "<input type='hidden' name='tunnel[id]' value='" . \Adminer\h($editing) . "'>\n" : '')
                    . $this->field('tunnel[name]', 'Name (optional)', $was['name'] ?? '', 'from the bastion and database')
                    . $this->field('tunnel[ssh]', 'Bastion', $was['ssh'] ?? '',
                        $hosts ? 'a Host from ~/.ssh/config' : 'user@host, or host:port', 'text', $hosts ? 'ssh-hosts' : '')
                    . $this->hosts($hosts)
                    . $this->auth($mode, $keys, (string) ($was['key'] ?? ''))
                    . $this->field('tunnel[username]', 'SSH username', $was['username'] ?? '',
                        $hosts ? 'from ~/.ssh/config' : '')
                    . $this->field('tunnel[secret]', 'x', '', '', 'password', '', 'ig-secret')
                    . $this->field('tunnel[host]', 'Database host, as the bastion sees it', $was['host'] ?? '', 'localhost')
                    . $this->field('tunnel[port]', 'Database port', $was['port'] ?? '', '5432')
                    . $this->field('tunnel[local]', 'Local port (optional)', $was['local'] ?: '', 'picked for you')
                    . "<p class='ig-acts'>"
                    . (\Instrument\Ssh\saving() ? "<button type='submit' name='tunnel[save]' value='1'>"
                        . ($editing !== '' ? 'Save changes' : 'Save') . "</button>" : '')
                    . "<button type='submit' name='tunnel[open]' value='1'>Open tunnel</button></p>\n";

                if (!\Instrument\Ssh\saving()) {
                    $fields .= "<p class='ig-note'>Nothing is saved: no volume is mounted for it.</p>\n";
                }

                // Open by default when there is nothing to come back to, and
                // whenever it is holding something — an edit in progress, or a
                // refusal to do what was asked.
                $wanted = $editing !== '' || $was || \Instrument\Ssh\state()['error'] !== ''
                    || !(\Instrument\Ssh\profiles() || \Instrument\Ssh\tunnels());

                return "<details" . ($wanted ? ' open' : '') . "><summary>"
                    . ($editing !== '' ? 'Editing' : 'Add a tunnel') . "</summary>\n" . $fields . "</details>\n";
            }

            /**
             * How to get in: a key, or an account and a password.
             *
             * Asked outright rather than worked out, because the two are told
             * apart by the prompt ssh puts on a tty — "Enter passphrase" for a
             * key, "Password:" for an account — and nothing that answers that
             * prompt on your behalf can tell which one it is looking at before
             * it arrives. One radio button here removes a guess that would
             * otherwise be wrong some of the time and slow the rest.
             *
             * @param list<string> $keys
             */
            private function auth(string $mode, array $keys, string $chosen): string
            {
                $pick = '';
                if ($keys) {
                    $options = "<option value=''>from ~/.ssh/config</option>";
                    foreach ($keys as $key) {
                        $options .= "<option value='" . \Adminer\h($key) . "'"
                            . ($key === $chosen ? ' selected' : '') . ">" . \Adminer\h($key) . "</option>";
                    }
                    $pick = "<label class='ig-keyed'>Key<select name='tunnel[key]'>" . $options . "</select></label>\n";
                }

                return "<div class='ig-auth'>"
                    . $this->radio('key', $mode, 'SSH key')
                    . $this->radio('password', $mode, 'Password')
                    . "</div>\n" . $pick;
            }

            private function radio(string $value, string $mode, string $label): string
            {
                return "<label><input type='radio' name='tunnel[auth]' value='" . $value . "'"
                    . ($mode === $value ? ' checked' : '') . "> " . \Adminer\h($label) . "</label>";
            }

            private function field(string $name, string $label, $value, string $placeholder, string $type = 'text', string $list = '', string $class = ''): string
            {
                return "<label" . ($class !== '' ? " class='" . $class . "'" : '') . ">" . \Adminer\h($label)
                    . "<input type='" . $type . "' name='" . \Adminer\h($name) . "'"
                    . " value='" . \Adminer\h((string) $value) . "'"
                    . ($placeholder !== '' ? " placeholder='" . \Adminer\h($placeholder) . "'" : '')
                    . ($list !== '' ? " list='" . \Adminer\h($list) . "'" : '')
                    . " autocapitalize='off' autocomplete='off'></label>\n";
            }

            /**
             * Follows the radio: the key picker only belongs to one of the two
             * modes, and the one secret field is a passphrase in one and a
             * password in the other. Rendered as one field rather than two so
             * there is never a second, hidden box quietly posting alongside it.
             *
             * Without JavaScript nothing is hidden and nothing is mislabelled:
             * the text below is what the server does with it either way.
             */
            private function script(): string
            {
                return \Adminer\script(
                    "(function () {"
                    . " var form = document.querySelector('.ig-tunnel');"
                    . " if (!form) return;"
                    . " var radios = form.querySelectorAll('[name=\"tunnel[auth]\"]');"
                    . " var keyed = form.querySelector('.ig-keyed');"
                    . " var secret = form.querySelector('.ig-secret');"
                    . " function sync() {"
                    . "  var byKey = form.querySelector('[name=\"tunnel[auth]\"][value=key]').checked;"
                    . "  if (keyed) keyed.hidden = !byKey;"
                    . "  if (secret) secret.firstChild.nodeValue ="
                    . "   byKey ? 'Key passphrase (optional), never saved' : 'SSH password, never saved';"
                    . " }"
                    . " for (var i = 0; i < radios.length; i++) radios[i].onclick = sync;"
                    . " sync();"
                    . "})();"
                );
            }

            /**
             * The Host aliases from a mounted config, as suggestions.
             *
             * A datalist and not a select: a bastion that is not in the config
             * yet should still be one thing you can type.
             *
             * @param list<string> $hosts
             */
            private function hosts(array $hosts): string
            {
                if (!$hosts) {
                    return '';
                }

                $options = '';
                foreach ($hosts as $host) {
                    $options .= "<option value='" . \Adminer\h($host) . "'>";
                }

                return "<datalist id='ssh-hosts'>" . $options . "</datalist>\n";
            }
        }

        /**
         * Keeps a PostgreSQL SQL export importable.
         *
         * Upstream writes several things it cannot read back:
         *
         *   - routines are rebuilt from information_schema, so an array
         *     parameter comes out as the bare word ARRAY and OUT/defaults/
         *     volatility are dropped — "type recipe_ids[] does not exist";
         *   - an extension's functions are dumped as bare C functions naming a
         *     symbol but no library — "could not access file" — and nothing
         *     says CREATE EXTENSION;
         *   - a column default loses its quotes with its cast, see
         *     withServerDefaults();
         *   - a partition is dumped as a table of its own, and every row a
         *     second time through its parent, which has no partitions yet —
         *     "no partition of relation found for row";
         *   - the foreign keys a partition inherits are added again, and a
         *     partitioned table gets ALTER TABLE ONLY, which it refuses.
         *
         * None of that has a hook of its own, so two output buffers do the
         * work. One holds what each database prints before its first table —
         * \connect, types, routines, a few kB — and rewrites it: routines from
         * pg_get_functiondef(), extension routines out, CREATE EXTENSION in.
         * The other stays open for the whole database and only ever removes
         * whole lines it was told about in advance: the foreign keys that the
         * server will already have made. Anything held back — a routine that
         * needs a table, a foreign key upstream lost — is written once every
         * table exists.
         *
         * It runs before every other plugin and returns null unless it wrote
         * the statement itself, so their dump hooks still fire.
         */
        /** Points the server-file import at an upload, see stream_upload(). */
        final class StreamImport extends \Adminer\Plugin
        {
            private bool $used = false;

            public function importServerPath()
            {
                // Once for the import itself; the form printed after it
                // should still name the real server file, not the upload.
                if ($this->used) {
                    return null;
                }
                $this->used = true;

                return stream_upload();
            }
        }

        /**
         * Keeps the "Permanent login" key on the /data volume. Upstream keeps
         * it in the temp dir, which here is a tmpfs: every restart or rebuild
         * threw the key away, and with it every saved login on the login page.
         */
        final class PermanentKey extends \Adminer\Plugin
        {
            public function permanentLogin($create = false)
            {
                $file = env('ADMINER_PERMANENT_KEY', '/data/adminer.key');
                if (!is_writable(dirname($file)) || is_link($file)) {
                    return null;
                }
                if (!$create && !file_exists($file)) {
                    return '';
                }
                $handle = @fopen($file, 'c+');
                if (!$handle) {
                    return null;
                }
                @chmod($file, 0600);
                flock($handle, LOCK_EX);
                $key = stream_get_contents($handle);
                if ($key === '' || $key === false) {
                    $key = bin2hex(random_bytes(16));
                    fwrite($handle, $key);
                }
                flock($handle, LOCK_UN);
                fclose($handle);

                return $key;
            }
        }

        final class PgDump extends \Adminer\Plugin
        {
            /** output-buffer level of the preamble buffer, while it is open */
            private ?int $level = null;

            /** output-buffer level of the line filter, while it is open */
            private ?int $filterLevel = null;

            /** the unterminated end of the last chunk the filter saw */
            private string $carry = '';

            /** @var array<string, true> exact lines the filter removes */
            private array $dropLines = [];

            private string $db = '';

            private bool $prepared = false;

            /** @var array<string, string> partition => its CREATE TABLE … PARTITION OF */
            private array $partitions = [];

            /** @var array<string, true> partitioned tables */
            private array $partitioned = [];

            /** statements held until every table exists */
            private string $deferred = '';

            /** @var array<string, array<string, string>> table => CHECK name => clause */
            private array $checks = [];

            private function active(): bool
            {
                return \Adminer\JUSH === 'pgsql' && ($_POST['format'] ?? '') === 'sql';
            }

            public function dumpDatabase($db)
            {
                $this->finishDatabase();
                if ($this->active()) {
                    $this->db = $db;
                    $this->prepared = false;
                    $this->partitions = $this->partitioned = $this->dropLines = [];
                    ob_start([$this, '_filter'], 1 << 16);
                    $this->filterLevel = ob_get_level();
                    ob_start();
                    $this->level = ob_get_level();
                }

                return null;
            }

            public function dumpTable($table, $style, $is_view = 0)
            {
                $this->release();
                if (!$this->active()) {
                    return null;
                }

                $this->prepare();
                // 1 is the late pass that writes the real views, after every
                // table and foreign key: the first point where all of them
                // exist, and before a view that may call a held-back routine.
                if ($is_view == 1) {
                    $this->flushDeferred();

                    return null;
                }
                if ($is_view != 0 || $style === '') {
                    return null;
                }

                // What Adminer's own dumpTable() prints for a table.
                $drop = $style === 'DROP+CREATE' ? 'DROP TABLE IF EXISTS ' . \Adminer\table($table) . ";\n" : '';

                if (isset($this->partitions[$table])) {
                    // Columns, keys and indexes all come from the parent.
                    echo $drop . $this->partitions[$table] . ";\n\n";

                    return true;
                }

                $sql = \Adminer\create_sql($table, $_POST['auto_increment'] ?? '', $style);
                if (!$sql) {
                    return null;
                }

                $sql = $this->withServerDefaults($table, $sql);
                // information_schema gives a CHECK that is one function call
                // without its parentheses, and "CHECK pos(qty)" is a syntax error.
                foreach ($this->checks[$table] ?? [] as $name => $clause) {
                    if (!str_starts_with($clause, '(')) {
                        $check = 'CONSTRAINT ' . \Adminer\idf_escape($name) . ' CHECK ';
                        $sql = str_replace($check . $clause, $check . "($clause)", $sql);
                    }
                }
                if (isset($this->partitioned[$table])) {
                    // ON ONLY leaves the index invalid and off the partitions;
                    // plain ON puts it on each partition as it is attached.
                    $sql = preg_replace('~^(CREATE (?:UNIQUE )?INDEX .*?) ON ONLY ~m', '$1 ON ', $sql);
                }
                echo $drop . $sql . ";\n\n";

                return true;
            }

            public function dumpData($table, $style, $query)
            {
                if (!$this->active() || $table === '') {
                    return null;
                }
                // A partitioned table's rows come out with its partitions.
                // Read through the parent they would be written twice, and the
                // first time into a table with no partition to take them yet.
                if (isset($this->partitioned[$table])) {
                    return true;
                }
                if ($style !== 'INSERT' && $style !== 'TRUNCATE+INSERT') {
                    return null;
                }

                $this->writeInserts($table, $style, $query);

                return true;
            }

            /**
             * Upstream's INSERT writer, byte for byte, without its per-value
             * cost — two thirds of a large export went there:
             *
             *   - it reads $generated[$column] on an empty array for every
             *     value, and each "Undefined array key" warning runs Adminer's
             *     error handler and a regex;
             *   - it matches every value's column type against two regexes,
             *     where once per column will do;
             *   - fetch_field() resolves each column's table with a query of
             *     its own (pg_field_table), only for the name the row's keys
             *     already carry.
             */
            private function writeInserts(string $table, string $style, string $query): void
            {
                if ($style === 'TRUNCATE+INSERT') {
                    echo \Adminer\truncate_sql($table) . ";\n";
                }

                $connection = \Adminer\connection();
                $result = $connection->query($query, 1);
                if (!$result) {
                    echo '-- ' . str_replace("\n", ' ', $connection->error) . "\n";

                    return;
                }

                $fields = \Adminer\fields($table);
                $numeric = \Adminer\number_type();
                $max = 1048576;                              // upstream's packet size
                $insert = '';
                $buffer = '';
                $plain = [];                                 // column => written unquoted when numeric
                $skip = [];                                  // generated columns

                while ($row = $result->fetch_assoc()) {
                    if ($insert === '') {
                        $columns = [];
                        foreach (array_keys($row) as $name) {
                            $field = $fields[$name] ?? [];
                            if (!empty($field['generated'])) {
                                $skip[$name] = true;
                                continue;
                            }
                            $columns[] = \Adminer\idf_escape($name);
                            $plain[$name] = preg_match($numeric, $field['type'] ?? '')
                                && !preg_match('~\[~', $field['full_type'] ?? '');
                        }
                        $insert = 'INSERT INTO ' . \Adminer\table($table) . ' (' . implode(', ', $columns) . ') VALUES';
                    }

                    foreach ($row as $name => $value) {
                        if (isset($skip[$name])) {
                            unset($row[$name]);
                        } elseif ($value === null) {
                            $row[$name] = 'NULL';
                        } elseif (!$plain[$name] || !is_numeric($value)) {
                            $row[$name] = $connection->quote($value);
                        }
                    }

                    $tuple = "\n(" . implode(",\t", $row) . ')';
                    if ($buffer === '') {
                        $buffer = $insert . $tuple;
                    } elseif (strlen($buffer) + 4 + strlen($tuple) + 2 < $max) {
                        $buffer .= ",$tuple";
                    } else {
                        echo $buffer . ";\n";
                        $buffer = $insert . $tuple;
                    }
                }

                if ($buffer !== '') {
                    echo $buffer . ";\n";
                }
            }

            public function dumpFooter()
            {
                $this->finishDatabase();

                return null;
            }

            /**
             * Puts each column default back the way the server stores it.
             *
             * Adminer strips the cast off a default and then re-quotes it only
             * for a text-like type, so 'draft'::status comes out as the bare
             * word draft ("cannot use column reference in DEFAULT expression")
             * and '2024-01-01'::date as 2024 minus 1 minus 1. Identity and
             * generated columns are Adminer's to write and are left alone.
             */
            private function withServerDefaults(string $table, string $sql): string
            {
                // attgenerated is PostgreSQL 12+; older servers get no rows and
                // the statement stays as Adminer wrote it.
                $defaults = \Adminer\get_key_vals(
                    "SELECT a.attname, pg_get_expr(d.adbin, d.adrelid)
FROM pg_attrdef d
JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
WHERE d.adrelid = " . \Adminer\driver()->tableOid($table) . " AND a.attidentity = '' AND a.attgenerated = ''"
                );

                foreach (\Adminer\fields($table) as $name => $field) {
                    if (!isset($defaults[$name])) {
                        continue;
                    }

                    $column = "\n    " . \Adminer\idf_escape($name) . ' ' . $field['full_type'];
                    $notNull = $field['null'] ? '' : ' NOT NULL';
                    $adminer = $column . \Adminer\default_value($field) . $notNull;
                    $at = strpos($sql, $adminer);
                    $next = $at === false ? '' : substr($sql, $at + strlen($adminer), 1);
                    if ($next === ',' || $next === "\n") {
                        $sql = substr_replace($sql, $column . ' DEFAULT ' . $defaults[$name] . $notNull, $at, strlen($adminer));
                    }
                }

                return $sql;
            }

            private function finishDatabase(): void
            {
                $this->release();
                $this->flushDeferred();
                if ($this->filterLevel !== null && ob_get_level() === $this->filterLevel) {
                    ob_end_flush();
                }
                $this->filterLevel = null;
            }

            private function flushDeferred(): void
            {
                echo $this->deferred;
                $this->deferred = '';
            }

            private function release(): void
            {
                if ($this->level === null) {
                    return;
                }

                // Someone else's buffer on top of ours: leave both alone, the
                // dump then comes out exactly as Adminer wrote it.
                $mine = ob_get_level() === $this->level;
                $this->level = null;
                if ($mine) {
                    echo $this->rewrite((string) ob_get_clean());
                }
            }

            /**
             * Output handler for the whole database: drops the lines listed in
             * $dropLines and passes everything else through untouched. Only
             * complete lines are judged, so a line split across two chunks is
             * held until its end arrives. Public for ob_start(); the leading
             * underscore keeps it from being taken for an Adminer hook.
             */
            public function _filter(string $chunk, int $phase): string
            {
                $text = $this->carry . $chunk;
                $this->carry = '';
                if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) {
                    $cut = strrpos($text, "\n");
                    $this->carry = $cut === false ? $text : substr($text, $cut + 1);
                    $text = $cut === false ? '' : substr($text, 0, $cut + 1);
                }

                if (!$this->dropLines || !str_contains($text, 'ALTER TABLE ONLY ')) {
                    return $text;
                }

                $lines = explode("\n", $text);
                foreach ($lines as $i => $line) {
                    if (isset($this->dropLines[$line])) {
                        unset($lines[$i]);
                    }
                }

                return implode("\n", $lines);
            }

            /**
             * Reads the partition layout and the foreign keys, once per
             * database and only once its schema is selected — which is not
             * yet the case when dumpDatabase() runs.
             */
            private function prepare(): void
            {
                if ($this->prepared) {
                    return;
                }
                $this->prepared = true;

                $schema = \Adminer\idf_escape(\Adminer\get_schema());

                // relispartition is PostgreSQL 10+; older servers get no rows.
                $rows = \Adminer\get_rows(
                    "SELECT c.relname, c.relkind, c.relispartition, pn.nspname AS parent_ns, p.relname AS parent,
    pg_get_expr(c.relpartbound, c.oid) AS bound,
    CASE WHEN c.relkind = 'p' THEN pg_get_partkeydef(c.oid) END AS partkey
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_inherits i ON i.inhrelid = c.oid AND c.relispartition
LEFT JOIN pg_class p ON p.oid = i.inhparent
LEFT JOIN pg_namespace pn ON pn.oid = p.relnamespace
WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND (c.relkind = 'p' OR c.relispartition)",
                    null,
                    ''
                );
                foreach ($rows as $row) {
                    if ($row['relkind'] === 'p') {
                        $this->partitioned[$row['relname']] = true;
                    }
                    if ($this->truthy($row['relispartition']) && $row['parent'] !== null) {
                        $this->partitions[$row['relname']] = "CREATE TABLE $schema." . \Adminer\idf_escape($row['relname'])
                            . ' PARTITION OF ' . \Adminer\idf_escape($row['parent_ns']) . '.' . \Adminer\idf_escape($row['parent'])
                            . ' ' . $row['bound']
                            . ($row['partkey'] !== null ? ' PARTITION BY ' . $row['partkey'] : '');
                    }
                }

                // Upstream's own query, for the whole schema at once instead of
                // once per table: information_schema is slow to read, and this
                // was a tenth of the export.
                $this->checks = [];
                foreach (\Adminer\get_rows(
                    "SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, CHECK_CLAUSE
FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS c
JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME
WHERE c.CONSTRAINT_SCHEMA = " . \Adminer\q($_GET['ns'] != '' ? $_GET['ns'] : \Adminer\DB) . " AND CHECK_CLAUSE NOT LIKE '% IS NOT NULL'",
                    null,
                    ''
                ) as $row) {
                    $row = array_change_key_case($row);
                    $this->checks[$row['table_name']][$row['constraint_name']] = $row['check_clause'];
                }

                $this->prepareForeignKeys($schema);
            }

            /**
             * Sets the foreign keys straight for the tables upstream gets
             * wrong — partitioned tables, and any table holding a constraint
             * the server cloned from a parent one (a partition's inherited
             * keys; the per-partition copies of a key that references a
             * partitioned table). Upstream keys its list by name, so those
             * copies can even overwrite the real key.
             *
             * What upstream would print is rebuilt with its own function and
             * compared with the constraints that were declared: lines only it
             * has are filtered out, lines only the server has are written
             * after the tables.
             */
            private function prepareForeignKeys(string $schema): void
            {
                // conparentid is PostgreSQL 11+; older servers get no rows.
                $tables = \Adminer\get_rows(
                    "SELECT c.relname, c.relkind
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')
    AND (c.relkind = 'p' OR EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = c.oid AND k.contype = 'f' AND k.conparentid <> 0))",
                    null,
                    ''
                );

                $selected = \Adminer\DB === '' ? null : (array) ($_POST['tables'] ?? []);
                foreach ($tables as $row) {
                    $table = $row['relname'];
                    if ($selected !== null && !in_array($table, $selected, true)) {
                        continue;
                    }

                    $adminer = array_filter(explode("\n", \Adminer\foreign_keys_sql($table)));
                    $server = [];
                    $keys = \Adminer\get_rows(
                        "SELECT conname, condeferrable::int AS deferrable, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = " . \Adminer\driver()->tableOid($table) . " AND contype = 'f' AND conparentid = 0
ORDER BY conname",
                        null,
                        ''
                    );
                    foreach ($keys as $key) {
                        $server[] = 'ALTER TABLE ' . ($row['relkind'] === 'p' ? '' : 'ONLY ') . "$schema." . \Adminer\idf_escape($table)
                            . ' ADD CONSTRAINT ' . \Adminer\idf_escape($key['conname']) . " $key[definition] "
                            . ($key['deferrable'] ? 'DEFERRABLE' : 'NOT DEFERRABLE') . ';';
                    }

                    foreach (array_diff($adminer, $server) as $line) {
                        $this->dropLines[$line] = true;
                    }
                    $missing = array_diff($server, $adminer);
                    if ($missing) {
                        $this->deferred .= implode("\n", $missing) . "\n\n";
                    }
                }
            }

            private function rewrite(string $dump): string
            {
                $routines = !empty($_POST['routines']);
                if ($routines) {
                    $dump = $this->withServerRoutines($dump);
                }

                if (empty($_POST['table_style']) && !$routines && empty($_POST['types'])) {
                    return $dump;
                }

                $schema = \Adminer\get_schema();
                $head = '';
                foreach ($this->extensions() as $extension) {
                    $head .= 'CREATE EXTENSION IF NOT EXISTS ' . \Adminer\idf_escape($extension)
                        . ' WITH SCHEMA ' . \Adminer\idf_escape($schema) . ";\n";
                }
                // As pg_dump does: a function body naming a table created
                // further down must not be checked while the routines load.
                $required = !$routines && !empty($_POST['table_style']) ? $this->requiredRoutines() : '';
                if (($routines || $required !== '') && $dump !== '') {
                    $head .= "SET check_function_bodies = false;\n";
                }
                if ($required !== '') {
                    $head .= "\n" . $required;
                }
                if ($head === '') {
                    return $dump;
                }

                // Mirrors the export loop: use_sql() plus ";\n\n", only when a
                // database style is chosen.
                $style = $_POST['db_style'] ?? '';
                $connect = $style !== '' ? \Adminer\use_sql($this->db, $style) . ";\n\n" : '';
                $at = $connect !== '' && str_starts_with($dump, $connect) ? strlen($connect) : 0;

                return substr_replace($dump, "$head\n", $at, 0);
            }

            /**
             * Rebuilds each routine exactly the way the export loop did and
             * swaps that text for the server's definition — or for nothing,
             * when an extension owns it. Matching the generated statement
             * rather than parsing the dump keeps a function body that happens
             * to contain SQL from ever being cut.
             *
             * A routine taking or returning a table's row type (SETOF recipe)
             * cannot be created before that table, and Adminer prints routines
             * first — those are held back and written once the tables are.
             * Trigger functions never are, so the triggers still find them.
             *
             * CREATE OR REPLACE, with no DROP in front: a DROP fails outright
             * on a trigger function still in use, and on a name that is
             * overloaded.
             */
            private function withServerRoutines(string $dump): string
            {
                // prokind is PostgreSQL 11+; on anything older this returns no
                // rows and the dump stays as Adminer wrote it.
                $rows = \Adminer\get_rows(
                    'SELECT r.specific_name AS "SPECIFIC_NAME", r.routine_type AS "ROUTINE_TYPE", r.routine_name AS "ROUTINE_NAME",
    EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = \'pg_proc\'::regclass AND d.objid = p.oid AND d.deptype = \'e\') AS extension,
    CASE WHEN p.prokind <> \'a\' THEN pg_get_functiondef(p.oid) END AS definition,
    EXISTS (
        SELECT 1 FROM pg_type t
        LEFT JOIN pg_type e ON e.oid = t.typelem
        WHERE t.oid = ANY (COALESCE(p.proallargtypes, p.proargtypes::oid[]) || p.prorettype)
            AND (t.typrelid <> 0 OR e.typrelid <> 0)
    ) AS uses_table
FROM information_schema.routines r
JOIN pg_proc p ON p.oid = substring(r.specific_name from \'_([0-9]+)$\')::oid
WHERE r.routine_schema = current_schema()',
                    null,
                    ''
                );

                $drop = ($_POST['db_style'] ?? '') !== 'DROP+CREATE';
                foreach ($rows as $row) {
                    $type = $row['ROUTINE_TYPE'];
                    $name = $row['ROUTINE_NAME'];
                    $adminer = ($drop ? "DROP $type IF EXISTS " . \Adminer\idf_escape($name) . ";;\n" : '')
                        . \Adminer\create_routine($type, ['name' => $name] + \Adminer\routine($row['SPECIFIC_NAME'], $type))
                        . ";\n\n";
                    $at = strpos($dump, $adminer);
                    if ($at === false) {
                        continue;
                    }

                    $server = '';
                    if ($this->truthy($row['extension'])) {
                        // the extension creates it
                    } elseif ($row['definition'] === null) {
                        $server = '-- Could not export aggregate ' . $name . "\n\n";
                    } elseif ($this->truthy($row['uses_table'])) {
                        $this->deferred .= rtrim($row['definition']) . ";\n\n";
                    } else {
                        $server = rtrim($row['definition']) . ";\n\n";
                    }
                    $dump = substr_replace($dump, $server, $at, strlen($adminer));
                }

                return $dump;
            }

            /**
             * The routines the tables cannot be created without — named in a
             * column default, a CHECK or an index expression — for a dump
             * made with Routines unticked. Upstream's form unticks it on its
             * own: it re-derives the box from Events, which PostgreSQL does
             * not have, so opening Export from a table page clears it. A
             * table whose DEFAULT is uuid_generate_v7() then fails to import.
             */
            private function requiredRoutines(): string
            {
                $definitions = \Adminer\get_vals(
                    "SELECT pg_get_functiondef(p.oid)
FROM pg_proc p
WHERE p.pronamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())
    AND p.prokind IN ('f', 'p')
    AND EXISTS (
        SELECT 1 FROM pg_depend d
        WHERE d.refclassid = 'pg_proc'::regclass AND d.refobjid = p.oid
            AND d.classid IN ('pg_attrdef'::regclass, 'pg_constraint'::regclass, 'pg_class'::regclass)
    )
    AND NOT EXISTS (SELECT 1 FROM pg_depend e WHERE e.classid = 'pg_proc'::regclass AND e.objid = p.oid AND e.deptype = 'e')
ORDER BY p.proname, p.oid"
                );

                $sql = '';
                foreach ($definitions as $definition) {
                    $sql .= rtrim($definition) . ";\n\n";
                }

                return $sql;
            }

            /** pgsql returns a boolean column as 't', PDO as a bool */
            private function truthy($value): bool
            {
                return in_array($value, ['t', true, 1, '1'], true);
            }

            /** @return list<string> extensions installed into the exported schema */
            private function extensions(): array
            {
                return \Adminer\get_vals(
                    "SELECT e.extname
FROM pg_extension e
JOIN pg_namespace n ON n.oid = e.extnamespace
WHERE n.nspname = current_schema() AND e.extname <> 'plpgsql'
ORDER BY e.extname"
                );
            }
        }

        $plugins =[new Theme(), new PageAssets(), new LoginDefaults(), new SshCard(), new StreamImport(), new PermanentKey(), new PgDump()];
        foreach (plugin_names() as $name) {
            array_push($plugins, ...load($name));
        }

        return new \Adminer\Plugins($plugins);
    }
}

namespace {

    /**
     * Behind a proxy that rewrites to /adminer.css, hand back the stylesheet
     * instead of the app. The built-in server answers these itself.
     */
    if (basename($_SERVER['DOCUMENT_URI'] ?? $_SERVER['REQUEST_URI'] ?? '') === 'adminer.css' && is_readable(__DIR__ . '/adminer.css')) {
        header('Content-Type: text/css');
        readfile(__DIR__ . '/adminer.css');
        exit;
    }

    function adminer_object()
    {
        return \Instrument\build();
    }

    // Before adminer.php, not after: this may rewrite the server being logged
    // into, and Adminer reads that the moment it is parsed.
    require_once __DIR__ . '/ssh.php';
    \Instrument\Ssh\handle();

    // Before adminer.php too: it decides between upload and server file as
    // soon as the import page runs.
    \Instrument\stream_upload();

    require __DIR__ . '/adminer.php';
}
