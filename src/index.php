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

        $plugins = [new Theme(), new PageAssets(), new LoginDefaults(), new SshCard()];
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

    require __DIR__ . '/adminer.php';
}
