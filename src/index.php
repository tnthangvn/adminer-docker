<?php
/**
 * Adminer Instrument — container bootstrap.
 *
 * Everything here runs before adminer.php takes over:
 *
 *   1. loads the plugin set named by ADMINER_PLUGINS (+ADD, -DISABLE),
 *   2. attaches the Instrument theme through the css() hook, so no file in
 *      the image ever has to be written at runtime,
 *   3. prefills the login form from ADMINER_DEFAULT_* variables.
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

    function build(): \Adminer\Plugins
    {
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
         * On the schema page only, adds the walker that replaces Adminer's
         * thousand-em diagram. Returns null so Adminer still prints its own
         * head — this only appends to it.
         */
        final class SchemaWalker extends \Adminer\Plugin
        {
            public function head($dark = null): ?bool
            {
                if (isset($_GET['schema'])) {
                    $stamp = @filemtime(__DIR__ . '/theme/schema.js') ?: 0;
                    echo '<link rel="stylesheet" href="theme/schema.css?v=' . $stamp . '">' . "\n";
                    echo \Adminer\script_src("theme/schema.js?v=$stamp", true);
                }

                return null;
            }
        }

        /** Prefills the login form from ADMINER_DEFAULT_* variables. */
        final class LoginDefaults extends \Adminer\Plugin
        {
            /** @var array<string,string> */
            private array $values;

            public function __construct()
            {
                $this->values = array_filter([
                    'driver'   => env('ADMINER_DEFAULT_DRIVER'),
                    'server'   => env('ADMINER_DEFAULT_SERVER'),
                    'username' => env('ADMINER_DEFAULT_USERNAME'),
                    'db'       => env('ADMINER_DEFAULT_DB'),
                ]);
            }

            public function loginFormField(string $name, string $heading, string $field): ?string
            {
                $value = $this->values[$name] ?? null;
                if ($value === null || isset($_POST['auth'])) {
                    return null;                     // let Adminer render it
                }

                $quoted = htmlspecialchars($value, ENT_QUOTES);

                if ($name === 'driver') {                    // a <select>
                    $field = preg_replace('~ selected(="[^"]*")?~', '', $field);
                    $field = str_replace("value=\"$quoted\"", "value=\"$quoted\" selected", $field);
                } elseif (str_contains($field, 'value=""')) { // an <input>
                    $field = str_replace('value=""', "value=\"$quoted\"", $field);
                } else {
                    $field = preg_replace('~(name="auth\[' . preg_quote($name, '~') . '\]")~', "$1 value=\"$quoted\"", $field, 1);
                }

                return $heading . $field . "\n";
            }
        }

        $plugins = [new Theme(), new SchemaWalker(), new LoginDefaults()];
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

    require __DIR__ . '/adminer.php';
}
