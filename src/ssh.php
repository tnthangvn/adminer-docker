<?php
/**
 * Adminer Instrument — SSH tunnels opened from the login form.
 *
 * Adminer has no SSH of its own, and no PHP driver can be handed a tunnelled
 * stream: mysqli and libpq each open their own socket, and neither takes one
 * you already have. So the tunnel is a real `ssh -L` process living beside the
 * application, and Adminer is pointed at the local end of it.
 *
 * Opening a tunnel and logging in are two separate acts, and the page says so:
 * the card above the login form opens a tunnel and tells you the local address
 * it landed on, and then you log in to that address with the ordinary form.
 * This is the shape the job already had — `ssh -L` in one terminal, Adminer
 * pointed at 127.0.0.1 in the other — with the terminal moved onto the page.
 *
 * Keeping them separate is what makes a tunnel worth having: one tunnel serves
 * as many logins, databases and sessions as you like, and a wrong database
 * password costs a retry rather than a reconnection.
 *
 * Everything here runs BEFORE adminer.php is parsed, because it has to take
 * the tunnel[...] fields out of $_POST: Adminer re-emits whatever it does not
 * recognise as hidden inputs, which would print the SSH password into the page.
 *
 * After login the local port is what lives in the URL, so later requests find
 * their tunnel by port and reopen it if it has died. That is the whole reason
 * there is a registry on disk rather than a variable in the session: the
 * built-in server runs four workers, and any of them may take the next request.
 *
 * ── what this trusts ──────────────────────────────────────────────────────
 * Anyone who can reach the login form can now make this container open an SSH
 * connection to a host of their choosing with credentials of their choosing.
 * That is the same trust Adminer already extends — its login form will connect
 * to any database host you type — but it is a bigger blast radius, so set
 * ADMINER_SSH_UI=off to remove the fields entirely.
 *
 * Credentials live in tmpfs (0600 in a 0700 directory, owned by the runtime
 * user) for as long as the tunnel does, and die with the container. A pasted
 * private key is written to that directory because ssh will not read one from
 * a pipe.
 *
 * Mount a ~/.ssh at ADMINER_SSH_CONFIG_DIR and none of that typing is needed:
 * the directory is staged into the tunnel's HOME, so `ssh` reads the config,
 * the keys and the known_hosts you already have, and the SSH server field
 * takes a Host alias like any other ssh command would.
 */

namespace Instrument\Ssh;

use function Instrument\env;

/** Where a driver listens when the Server field does not say. */
const DEFAULT_PORTS = [
    'server' => 3306, 'pgsql' => 5432, 'oracle' => 1521, 'mssql' => 1433,
    'mongo' => 27017, 'redis' => 6379, 'elastic' => 9200, 'clickhouse' => 8123,
    'firebird' => 3050,
];

/** Local ports handed out to tunnels. */
const PORT_RANGE = [13000, 13999];

function enabled(): bool
{
    return !in_array(strtolower(env('ADMINER_SSH_UI', 'on')), ['0', 'off', 'false', 'no'], true);
}

/** tmpfs, 0700: the private keys and the registry live here. */
function dir(): string
{
    $dir = env('ADMINER_SSH_HOME', '/tmp/adminer-ssh') . '/ui';
    if (!is_dir($dir)) {
        @mkdir($dir . '/keys', 0700, true);
    }

    return $dir;
}

/**
 * Runs $edit against the registry with an exclusive lock held throughout, and
 * writes back whatever it returns.
 *
 * Four worker processes can be opening tunnels at once, and two of them
 * choosing the same local port would leave one login failing for a reason
 * nobody could reproduce. The lock spans the spawn as well as the read, so the
 * port a worker picked is written down before any other worker looks. Anything
 * $edit needs to report back it takes by reference.
 *
 * @param callable(array): array $edit  receives the registry, returns it back
 * @return array                        the registry as written
 */
function with_registry(callable $edit): array
{
    $file = dir() . '/tunnels.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        error_log('adminer: cannot open the SSH tunnel registry');

        return [];
    }

    flock($handle, LOCK_EX);
    clearstatcache(true, $file);
    $size = (int) filesize($file);
    $tunnels = $size ? (json_decode((string) fread($handle, $size), true) ?: []) : [];

    $tunnels = $edit($tunnels);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($tunnels));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0600);

    return $tunnels;
}

/**
 * A HOME for ssh, with your own ~/.ssh staged into it.
 *
 * Mounting a config is not enough on its own: `ssh` rejects a private key it
 * believes anyone can read, and a bind mount carries the host's permissions
 * and the host's owner, so a perfectly good ~/.ssh mounted straight in is
 * refused key by key. The directory is therefore copied into tmpfs at 0600
 * inside a 0700 HOME — which also gives ssh somewhere to append a new host key,
 * something a read-only mount cannot offer.
 *
 * Two things have to be true for a mounted config to work at all, and neither
 * is obvious: ssh finds the per-user config through getpwuid(), not $HOME — so
 * the image's home of /app is what it looks in, whatever we export — and it
 * expands `~` the same way, so `IdentityFile ~/.ssh/id_rsa` would point at
 * /app/.ssh/id_rsa and fail with "no such identity". The config is therefore
 * passed explicitly with -F, and every `~/` in it is rewritten to this
 * directory on the way in.
 */
function home(): string
{
    static $home = null;
    if ($home !== null) {
        return $home;
    }

    $home = dir() . '/home';
    $dot = "$home/.ssh";
    if (!is_dir($dot)) {
        @mkdir($dot, 0700, true);
    }
    @chmod($dot, 0700);

    $source = env('ADMINER_SSH_CONFIG_DIR', '/ssh');
    if (!is_dir($source) || !($names = @scandir($source))) {
        return $home;
    }

    // Restage only when the mount has changed, not on every page load.
    $stamp = "$home/.staged";
    $version = (string) @filemtime($source);
    foreach ($names as $name) {
        $version .= ':' . $name . '@' . (string) @filemtime("$source/$name");
    }
    $version = hash('sha256', $version);
    if (@file_get_contents($stamp) === $version) {
        return $home;
    }

    // Rebuilt from the mount below — never appended to twice.
    @unlink("$dot/config");

    foreach ($names as $name) {
        $from = "$source/$name";
        if ($name[0] === '.' || !is_file($from)) {
            continue;
        }
        // A private key is 0600 and belongs to whoever made it, so a mounted
        // ~/.ssh is unreadable to a container running as anyone else — see
        // staging_error(), which is what says so out loud.
        if (!is_readable($from)) {
            continue;
        }
        // Keys and configs are small; anything large is not one of ours.
        if (filesize($from) > 512 * 1024) {
            continue;
        }
        if (!@copy($from, "$dot/$name")) {
            continue;
        }
        @chmod("$dot/$name", 0600);

        if ($name === 'config') {
            // `~` and ssh's own %d both mean "the home ssh thinks you have",
            // which is not this one. Spell it out instead.
            $text = (string) @file_get_contents("$dot/$name");
            @file_put_contents("$dot/$name", str_replace(['~/', '%d/'], "$home/", $text));
            @chmod("$dot/$name", 0600);
        }
    }

    stage_identities($dot);
    @file_put_contents($stamp, $version);

    return $home;
}

/**
 * Names the staged keys in the staged config, under `Host *`.
 *
 * Without this, a bastion typed in by hand rather than named in the config
 * gets ssh's *built-in* default identities — `~/.ssh/id_rsa` and friends,
 * resolved against the home in /etc/passwd, which is the read-only /app. The
 * keys would be sitting right there, staged and unused, and the failure would
 * read "Permission denied (publickey)" with nothing to suggest why.
 *
 * IdentityFile accumulates rather than being overridden, and a `Host *` block
 * at the end still applies, so a key named against a specific alias is still
 * tried first. These are only the fallback.
 */
function stage_identities(string $dot): void
{
    $keys = [];
    foreach ((array) @scandir($dot) as $name) {
        $file = "$dot/$name";
        if (!is_file($file) || str_ends_with($name, '.pub') || in_array($name, ['config', 'known_hosts', 'authorized_keys'], true)) {
            continue;
        }
        if (str_contains((string) @file_get_contents($file, false, null, 0, 200), 'PRIVATE KEY')) {
            $keys[] = $file;
        }
    }
    if (!$keys) {
        return;
    }

    $block = "\n\n# Added by Adminer Instrument: the staged keys, as a fallback for hosts\n"
        . "# this config does not name. ssh's own defaults point at the image's home.\n"
        . "Host *\n";
    foreach ($keys as $key) {
        $block .= "  IdentityFile $key\n";
    }

    @file_put_contents("$dot/config", $block, FILE_APPEND);
    @chmod("$dot/config", 0600);
}

/**
 * Files in the mount this container is not allowed to read.
 *
 * A private key is 0600 and belongs to whoever made it, so a mounted ~/.ssh is
 * unreadable to a container running as anyone else — and the failure surfaces
 * much later as "no such identity" for a key that is plainly right there. Said
 * on the page rather than logged, because that is where the person is looking.
 *
 * Asked fresh each time rather than recorded while staging: staging happens
 * once per container, and the answer is needed on every page after that.
 */
function staging_error(): string
{
    $source = env('ADMINER_SSH_CONFIG_DIR', '/ssh');
    if (!is_dir($source)) {
        return '';
    }

    $unreadable = [];
    foreach ((array) @scandir($source) as $name) {
        $from = "$source/$name";
        if ($name[0] !== '.' && is_file($from) && !is_readable($from)) {
            $unreadable[] = $from;
        }
    }
    if (!$unreadable) {
        return '';
    }

    return 'Cannot read ' . implode(', ', $unreadable)
        . ' — those keys belong to another user. Rebuild as your own uid:'
        . ' HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d --build';
}

/** The staged ~/.ssh/config, if a mount provided one. */
function config_file(): string
{
    $file = home() . '/.ssh/config';

    return is_readable($file) ? $file : '';
}

/**
 * Host aliases from the staged config, for the login form to offer.
 *
 * Patterns are skipped: `Host *` is settings for everything, not a bastion you
 * can pick.
 *
 * @return list<string>
 */
function config_hosts(): array
{
    $file = home() . '/.ssh/config';
    if (!is_readable($file)) {
        return [];
    }

    $hosts = [];
    foreach ((array) @file($file) as $line) {
        if (!preg_match('~^\s*Host\s+(.+)$~i', (string) $line, $m)) {
            continue;
        }
        foreach (preg_split('~\s+~', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $alias) {
            if (!preg_match('~[*?!]~', $alias)) {
                $hosts[$alias] = true;
            }
        }
    }

    return array_keys($hosts);
}

/** Has ssh anything of its own to authenticate with — a config, or a key? */
function identities(): bool
{
    static $any = null;
    if ($any !== null) {
        return $any;
    }

    $dot = home() . '/.ssh';
    $any = is_readable("$dot/config");
    foreach ((array) @glob("$dot/id_*") as $file) {
        $any = $any || !str_ends_with($file, '.pub');
    }

    return $any;
}

/**
 * The private keys staged from the mount, by file name.
 *
 * What makes a file a private key here is that it says so: a `.pub` is its
 * public half, `config` and `known_hosts` are not keys at all, and everything
 * else is judged on whether the text begins like a key.
 *
 * @return list<string>
 */
function private_keys(): array
{
    $dot = home() . '/.ssh';
    $keys = [];
    foreach ((array) @scandir($dot) as $name) {
        $file = "$dot/$name";
        if (!is_file($file) || str_ends_with($name, '.pub')
            || in_array($name, ['config', 'known_hosts', 'authorized_keys'], true)) {
            continue;
        }
        if (str_contains((string) @file_get_contents($file, false, null, 0, 200), 'PRIVATE KEY')) {
            $keys[] = $name;
        }
    }
    sort($keys);

    return $keys;
}

/** One of those by name, as a path ssh can use. '' if it is not one of them. */
function key_path(string $name): string
{
    return $name !== '' && in_array($name, private_keys(), true) ? home() . "/.ssh/$name" : '';
}

/** Does this key want a passphrase? ssh-keygen answers exactly. */
function key_encrypted(string $path): bool
{
    @exec('ssh-keygen -y -P "" -f ' . escapeshellarg($path) . ' 2>/dev/null', $output, $status);

    return $status !== 0;
}

/**
 * Whoever opened a tunnel, as a cookie.
 *
 * A tunnel is a listening port on the loopback, and the port is in the URL —
 * so a second person using the same Adminer could type it into the Server box
 * and ride someone else's bastion. They would still need the database's own
 * credentials, and Adminer would let them type any other host in the world
 * into that same box, but "a port that is already past the firewall" deserves
 * more than nothing.
 *
 * This is the "more than nothing": a tunnel is only reused, kept alive and
 * closed for the browser that opened it. It does NOT wall off a port that is
 * currently up — anyone who guesses it can still connect until it is swept —
 * so what it really buys is that a stranger's traffic never renews the idle
 * timer, and an abandoned tunnel goes away on schedule. Set ADMINER_SSH_UI=off
 * if that is not enough for where this is running.
 */
function owner(): string
{
    static $owner = null;
    if ($owner !== null) {
        return $owner;
    }

    $owner = (string) ($_COOKIE['adminer_ssh'] ?? '');
    if (!preg_match('~^[0-9a-f]{32}$~', $owner)) {
        $owner = bin2hex(random_bytes(16));
        // handle() runs before adminer.php prints anything, so this lands.
        @setcookie('adminer_ssh', $owner, [
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
        ]);
        $_COOKIE['adminer_ssh'] = $owner;
    }

    return $owner;
}

/** Is something listening on the local end? */
function alive(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 1);
    if (!$socket) {
        return false;
    }
    fclose($socket);

    return true;
}

/**
 * A local port nothing is using.
 *
 * Asking the kernel for port 0 and reading back what it gave us is the only
 * way to be sure; the socket is closed immediately after, so there is a race,
 * which is why ssh is launched with ExitOnForwardFailure and the caller retries.
 */
function free_port(array $taken): int
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            break;
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        if ($port && !isset($taken[$port])) {
            return $port;
        }
    }

    // The kernel would not say. Walk our own range instead.
    for ($port = PORT_RANGE[0]; $port <= PORT_RANGE[1]; $port++) {
        if (!isset($taken[$port]) && !alive($port)) {
            return $port;
        }
    }

    return 0;
}

/**
 * Splits "host:port", "[::1]:5432" into [host, port].
 *
 * $default of 0 means "do not invent one" — which is how an SSH host given as
 * a bare `prod-bastion` keeps its Port from ~/.ssh/config instead of having 22
 * forced onto it by a -p we added ourselves.
 *
 * @return array{0: string, 1: int}
 */
function split_host(string $value, int $default): array
{
    $value = trim($value);
    if ($value === '') {
        return ['', $default];
    }

    if (preg_match('~^\[(?<host>[^\]]+)\](?::(?<port>\d+))?$~', $value, $m)) {    // [::1]:5432
        return [$m['host'], (int) ($m['port'] ?? 0) ?: $default];
    }
    if (substr_count($value, ':') === 1 && preg_match('~^(?<host>.+):(?<port>\d+)$~', $value, $m)) {
        return [$m['host'], (int) $m['port']];
    }

    return [$value, $default];                                                   // bare host, or IPv6
}

/**
 * Host key policy.
 *
 * A known_hosts that came from your own ~/.ssh is the best answer there is —
 * those are host keys you have already met — so it is used as-is and strictly.
 * With nothing to go on, the first key offered is accepted, which gets you
 * moving but cannot tell a first connection from a man in the middle.
 */
function host_key_options(): array
{
    $known = env('ADMINER_SSH_KNOWN_HOSTS');
    if ($known !== '' && is_readable($known)) {
        return ['-o', 'StrictHostKeyChecking=yes', '-o', "UserKnownHostsFile=$known"];
    }

    // Spelled out for the same reason as the config: ssh would otherwise look
    // for known_hosts under the home in /etc/passwd, which is read-only.
    $known = home() . '/.ssh/known_hosts';
    if (is_readable($known) && filesize($known) > 0) {
        return ['-o', 'StrictHostKeyChecking=yes', '-o', "UserKnownHostsFile=$known"];
    }
    if (!file_exists($known)) {
        @touch($known);
        @chmod($known, 0600);
    }

    return ['-o', 'StrictHostKeyChecking=accept-new', '-o', "UserKnownHostsFile=$known"];
}

/**
 * Starts one ssh -L and waits to hear whether it worked.
 *
 * `-f` is what makes the tunnel outlive this request: ssh authenticates, sets
 * up the forward, and only then forks into the background — so the process we
 * wait on here exits non-zero, with a usable message on stderr, for every
 * failure that matters (bad key, refused password, unknown host, a database
 * port the bastion cannot reach). A backgrounded process could not tell us any
 * of that.
 *
 * @return string  '' on success, else the reason
 */
function spawn(array $ssh, string $dbHost, int $dbPort, int $port): string
{
    $home = home();
    $secret = $ssh['password'];
    $byKey = $ssh['mode'] !== 'password';
    $command = [];
    $env = ['HOME' => $home];

    // A backstop, not a nicety. sshpass matches one prompt word; in front of
    // any other prompt it waits, ssh waits with it, ConnectTimeout was long
    // since satisfied, and the request would hang until the browser gave up.
    array_push($command, 'timeout', (string) ((int) env('ADMINER_SSH_TIMEOUT', '10') + 15));

    if ($secret !== '') {
        // sshpass answers the prompt through a pty. The secret goes in the
        // environment, never on the command line, so it stays out of `ps`.
        //
        // Which prompt is coming cannot be read off a failed attempt — ssh asks
        // on a tty and sshpass simply never matches the wrong word — so the
        // form asks instead. "Enter passphrase" for a key, "Password:" for an
        // account, and -P says which one to watch for.
        $env['SSHPASS'] = $secret;
        array_push($command, 'sshpass', '-e');
        if ($byKey) {
            array_push($command, '-P', 'passphrase');
        }
    } elseif (!$byKey) {
        return 'Password authentication needs a password.';
    } elseif ($ssh['key'] === '' && !identities()) {
        return 'Key authentication needs a key — mount your ~/.ssh, or use a password.';
    }

    $command[] = 'ssh';
    if (($config = config_file()) !== '') {
        array_push($command, '-F', $config);
    }
    if ($byKey) {
        if ($ssh['key'] !== '') {
            array_push($command, '-o', 'IdentitiesOnly=yes', '-i', $ssh['key']);
        }
    } else {
        // Asked for a password, so do not let a key that happens to be lying
        // around answer instead — that would succeed for the wrong reason, and
        // keep succeeding until the day the key is gone.
        array_push($command, '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'PubkeyAuthentication=no');
    }
    if ($secret === '') {
        // Nothing to type back, so a prompt can only hang. Fail loudly instead.
        array_push($command, '-o', 'BatchMode=yes');
    }

    array_push($command, '-f', '-N', '-T');
    array_push($command, '-o', 'ExitOnForwardFailure=yes');
    array_push($command, '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3');
    array_push($command, '-o', 'ConnectTimeout=' . (int) env('ADMINER_SSH_TIMEOUT', '10'));
    array_push($command, ...host_key_options());
    // Only when it was actually typed: a bare `prod-bastion` must keep the
    // Port and the User that ~/.ssh/config already gives it.
    if ($ssh['port'] > 0) {
        array_push($command, '-p', (string) $ssh['port']);
    }
    array_push($command, '-L', "127.0.0.1:$port:$dbHost:$dbPort");
    $command[] = ($ssh['username'] !== '' ? $ssh['username'] . '@' : '') . $ssh['host'];

    $line = implode(' ', array_map('escapeshellarg', $command));
    $process = @proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $home, $env);
    if (!is_resource($process)) {
        return 'Could not start ssh.';
    }

    $stderr = (string) stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $status = proc_close($process);
    if ($status === 0) {
        return '';
    }

    return clean_error($stderr, $status);
}

/**
 * ssh is chatty and repetitive; keep the line that says what went wrong.
 *
 * When sshpass is the one that gives up, there is nothing on stderr at all —
 * it swallowed the prompt it was answering — so its exit status is the only
 * evidence, and "wrong password" reads better than "SSH connection failed".
 */
function clean_error(string $stderr, int $status = 0): string
{
    $lines = array_filter(array_map('trim', explode("\n", $stderr)), function ($line) {
        return $line !== ''
            && !str_starts_with($line, 'Warning: Permanently added')
            && !preg_match('~^(@|It is possible|The authenticity|Someone could|Please contact|Add correct|Offending|Host key verification failed)~', $line)
            && !str_contains($line, 'Pseudo-terminal');
    });

    $lines = array_slice(array_values($lines), 0, 3);
    if ($lines) {
        return implode(' ', $lines);
    }

    switch ($status) {
        case 5:   return 'Wrong SSH password or key passphrase.';
        case 6:   return 'The bastion is not in known_hosts.';
        case 7:   return "The bastion's host key has changed — check known_hosts before trusting it.";
        case 124: return 'The bastion did not answer in time.';
        default:  return 'SSH connection failed.';
    }
}

/** Kills the tunnel holding a local port. */
function kill(int $port): void
{
    @exec('pkill -f ' . escapeshellarg("127.0.0.1:$port:") . ' 2>/dev/null');
}

/**
 * Opens a tunnel, or reuses the one that is already up for the same target.
 *
 * Reuse is what keeps a bookmarked URL working: the same bastion and the same
 * database always land on the same local port, so the address Adminer put in
 * the URL after login still means something an hour later.
 *
 * @return array{port: int, error: string}
 */
function open(array $ssh, string $dbHost, int $dbPort, int $wanted = 0): array
{
    $id = hash('sha256', implode("\0", [
        $ssh['host'], $ssh['port'], $ssh['username'], $dbHost, $dbPort,
        $ssh['key'] !== '' ? 'k:' . $ssh['key'] : 'p:' . hash('sha256', $ssh['password']),
    ]));

    $result = null;
    with_registry(function (array $tunnels) use ($id, $ssh, $dbHost, $dbPort, $wanted, &$result) {
        $tunnels = sweep($tunnels);

        foreach ($tunnels as $port => $tunnel) {
            if ($tunnel['id'] === $id && ($tunnel['owner'] ?? '') === owner() && alive((int) $port)) {
                $tunnels[$port]['used'] = time();
                $result = ['port' => (int) $port, 'error' => '', 'reused' => true];

                return $tunnels;
            }
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // A port asked for by name is worth one try; after that, or when it
            // is already taken, the kernel picks and the page reports back.
            $port = $attempt === 0 && $wanted > 0 && !isset($tunnels[$wanted])
                ? $wanted
                : free_port($tunnels);
            if (!$port) {
                $result = ['port' => 0, 'error' => 'No local port is free for a tunnel.', 'reused' => false];

                return $tunnels;
            }

            $error = spawn($ssh, $dbHost, $dbPort, $port);
            if ($error === '') {
                $tunnels[$port] = [
                    'id' => $id, 'owner' => owner(), 'ssh' => $ssh,
                    'host' => $dbHost, 'port' => $dbPort,
                    'used' => time(), 'opened' => time(),
                ];
                $result = ['port' => $port, 'error' => '', 'reused' => false];

                return $tunnels;
            }
            // Only a lost race is worth another port; anything else will fail
            // again the same way, and the user should hear about it now.
            if (!str_contains($error, 'Address already in use') && !str_contains($error, 'cannot listen')) {
                $result = ['port' => 0, 'error' => $error, 'reused' => false];

                return $tunnels;
            }
        }

        $result = ['port' => 0, 'error' => 'Could not bind a local port for the tunnel.', 'reused' => false];

        return $tunnels;
    });

    return $result ?: ['port' => 0, 'error' => 'SSH connection failed.', 'reused' => false];
}

/**
 * Brings a tunnel back if it has dropped, so a bastion reboot costs a page
 * refresh and not a re-login.
 */
function revive(int $port): bool
{
    $ok = false;
    with_registry(function (array $tunnels) use ($port, &$ok) {
        $tunnels = sweep($tunnels);
        if (!isset($tunnels[$port])) {
            $ok = false;

            return $tunnels;
        }

        $tunnels[$port]['used'] = time();
        $ok = alive($port) || spawn($tunnels[$port]['ssh'], $tunnels[$port]['host'], $tunnels[$port]['port'], $port) === '';

        return $tunnels;
    });

    return (bool) $ok;
}

/* ── saved tunnels ─────────────────────────────────────────────────────────
 *
 * Open tunnels live in tmpfs and die with the container, which is right for a
 * running process and wrong for the description of one. A tunnel you use every
 * day should survive a `docker compose up`, so the descriptions go in a volume
 * instead: a small JSON file, shared by everyone who can reach the page, the
 * same way ~/.ssh/config is shared by everyone who can read it.
 *
 * What is NOT in that file is any secret. A saved tunnel names a bastion, a
 * database and a port; the key comes from ~/.ssh/config, and a passphrase, if
 * the key has one, is typed each time. Writing a bastion password to a volume
 * to save that typing is a bad trade, and this file does not offer it.
 */

/** Where the saved tunnels live. Empty when there is nowhere to write them. */
function profiles_file(): string
{
    $file = env('ADMINER_SSH_PROFILES', '/data/tunnels.json');
    $dir = dirname($file);

    return is_dir($dir) && is_writable($dir) ? $file : '';
}

/** Can tunnels be saved at all, or is the volume missing? */
function saving(): bool
{
    return profiles_file() !== '';
}

/**
 * Runs $edit against the saved tunnels under an exclusive lock.
 *
 * Same shape as with_registry, and separate from it on purpose: one is state
 * that must not outlive the container, the other is the only thing here that
 * must.
 *
 * @param (callable(array): array)|null $edit  null to read without writing
 * @return list<array<string, mixed>>
 */
function with_profiles(?callable $edit = null): array
{
    $file = profiles_file();
    if ($file === '') {
        return [];
    }

    $handle = @fopen($file, 'c+');
    if (!$handle) {
        error_log('adminer: cannot open the saved tunnels at ' . $file);

        return [];
    }

    flock($handle, $edit ? LOCK_EX : LOCK_SH);
    clearstatcache(true, $file);
    $size = (int) filesize($file);
    $saved = $size ? (json_decode((string) fread($handle, $size), true) ?: []) : [];
    $saved = array_values(array_filter($saved, 'is_array'));

    if ($edit) {
        $saved = array_values($edit($saved));
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $saved;
}

/** @return list<array<string, mixed>> */
function profiles(): array
{
    return with_profiles();
}

/** One saved tunnel by id, or null. */
function profile(string $id): ?array
{
    foreach (profiles() as $saved) {
        if (($saved['id'] ?? '') === $id) {
            return $saved;
        }
    }

    return null;
}

/**
 * Creates or replaces a saved tunnel.
 *
 * @param array<string, mixed> $form
 * @return string '' on success, else the reason
 */
function save_profile(array $form): string
{
    if (!saving()) {
        return 'There is nowhere to save tunnels — mount a volume, see ADMINER_SSH_PROFILES.';
    }

    [$sshHost] = split_host((string) ($form['ssh'] ?? ''), 0);
    $port = (int) ($form['port'] ?? 0);
    if ($sshHost === '') {
        return 'Name the bastion — a Host from ~/.ssh/config, or host[:port].';
    }
    if ($port < 1 || $port > 65535) {
        return 'Give the database port as the bastion sees it.';
    }

    $id = preg_replace('~[^a-f0-9]~', '', (string) ($form['id'] ?? '')) ?: bin2hex(random_bytes(8));
    $entry = [
        'id' => $id,
        'name' => trim((string) ($form['name'] ?? '')),
        'ssh' => trim((string) ($form['ssh'] ?? '')),
        // Which way it authenticates, and with which key — but never the
        // secret either way. A password profile asks for the password every
        // time, on purpose.
        'auth' => ($form['auth'] ?? '') === 'password' ? 'password' : 'key',
        'key' => trim((string) ($form['key'] ?? '')),
        'username' => trim((string) ($form['username'] ?? '')),
        'host' => trim((string) ($form['host'] ?? '')) ?: 'localhost',
        'port' => $port,
        'local' => (int) ($form['local'] ?? 0),
    ];
    if ($entry['name'] === '') {
        $entry['name'] = $entry['ssh'] . ' · ' . $entry['host'] . ':' . $entry['port'];
    }

    with_profiles(function (array $saved) use ($entry) {
        foreach ($saved as $i => $existing) {
            if (($existing['id'] ?? '') === $entry['id']) {
                $saved[$i] = $entry;

                return $saved;
            }
        }
        $saved[] = $entry;

        return $saved;
    });

    return '';
}

/** Forgets a saved tunnel. Any tunnel it opened stays up until it is closed. */
function delete_profile(string $id): void
{
    with_profiles(fn(array $saved) => array_filter($saved, fn($e) => ($e['id'] ?? '') !== $id));
}

/**
 * This browser's open tunnels, for the card to list.
 *
 * @return array<int, array{via: string, target: string}>
 */
function tunnels(): array
{
    $mine = [];
    foreach (with_registry(fn(array $tunnels) => sweep($tunnels)) as $port => $tunnel) {
        if (($tunnel['owner'] ?? '') !== owner()) {
            continue;
        }
        $via = $tunnel['ssh']['host'];
        if (($tunnel['ssh']['username'] ?? '') !== '') {
            $via = $tunnel['ssh']['username'] . '@' . $via;
        }
        if (($tunnel['ssh']['port'] ?? 0) > 0) {
            $via .= ':' . $tunnel['ssh']['port'];
        }
        $mine[(int) $port] = ['via' => $via, 'target' => $tunnel['host'] . ':' . $tunnel['port']];
    }

    return $mine;
}

/** Drops tunnels nobody has used for ADMINER_SSH_IDLE seconds. */
function sweep(array $tunnels): array
{
    $idle = (int) env('ADMINER_SSH_IDLE', '1800');
    if ($idle <= 0) {
        return $tunnels;
    }

    foreach ($tunnels as $port => $tunnel) {
        if (time() - (int) $tunnel['used'] > $idle) {
            kill((int) $port);
            unset($tunnels[$port]);
        }
    }

    return $tunnels;
}

/** Closes a tunnel on logout, rather than leaving it open for the idle timer. */
function close(int $port): void
{
    with_registry(function (array $tunnels) use ($port) {
        if (isset($tunnels[$port])) {
            kill($port);
            unset($tunnels[$port]);
        }

        return $tunnels;
    });
}

/**
 * What the card needs to redraw itself: any error, the values that produced it,
 * and the port a successful open (or a "use") wants the Server field set to.
 */
function state(?array $set = null): array
{
    static $state = ['error' => '', 'note' => '', 'form' => [], 'opened' => 0, 'editing' => ''];
    if ($set !== null) {
        $state = $set + $state;
    }

    return $state;
}

/**
 * The whole of this file's effect on a request.
 *
 * Called before adminer.php is parsed because tunnel[...] has to be out of
 * $_POST before Adminer re-emits whatever it does not recognise as hidden
 * inputs, which would put the SSH password in the page source.
 */
function handle(): void
{
    $form = $_POST['tunnel'] ?? null;
    unset($_POST['tunnel']);

    if (!enabled()) {
        return;
    }

    // Somebody who simply closes the tab never calls open() or revive() again,
    // so without this their tunnel would outlive its idle timeout forever.
    $registry = dir() . '/tunnels.json';
    if (@filesize($registry) > 2) {
        with_registry(fn(array $tunnels) => sweep($tunnels));
    }

    // One form serves both the card and the login, so the button that was
    // pressed is the only thing that says which. Its fields ride along on an
    // ordinary login and must be ignored there — not merely left unread, since
    // acting on them would cancel the login that was actually asked for.
    if (is_array($form) && array_intersect_key($form, array_flip(['open', 'close', 'use', 'save', 'edit', 'delete', 'start', 'from']))) {
        unset($_POST['auth']);
        card($form);

        return;
    }

    // Any later request against a tunnelled server: make sure it is still there.
    // Including after a logout — the tunnel is not part of the login and
    // outlives it, since one tunnel serves as many logins and databases as you
    // point at it. The card closes it, or the idle timer does.
    if ($port = tunnel_port()) {
        revive($port);
    }
}

/** 127.0.0.1:13042 => 13042, for a port we opened and this browser owns. */
function tunnel_port(): int
{
    $ports = [];
    foreach ($_GET as $value) {
        if (is_string($value) && preg_match('~^(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)$~', trim($value), $m)) {
            $ports[] = (int) $m[1];
        }
    }
    if (!$ports) {
        return 0;
    }

    $tunnels = with_registry(fn(array $tunnels) => $tunnels);
    foreach ($ports as $port) {
        if (isset($tunnels[$port]) && ($tunnels[$port]['owner'] ?? '') === owner()) {
            return $port;
        }
    }

    return 0;
}

/**
 * Acts on the card: open a tunnel, close one, or point the login form at one.
 *
 * @param array<string, mixed> $form
 */
function card(array $form): void
{
    if (($close = (int) ($form['close'] ?? 0)) > 0) {
        if (isset(tunnels()[$close])) {
            close($close);
        }

        return;
    }

    if (($use = (int) ($form['use'] ?? 0)) > 0) {
        if (isset(tunnels()[$use])) {
            state(['opened' => $use]);
        }

        return;
    }

    if (isset($form['edit'])) {
        $saved = profile((string) $form['edit']);
        // The secret is deliberately not among the fields that come back: it
        // was never saved, so there is nothing to put in it.
        state(['form' => $saved ?? [], 'editing' => $saved['id'] ?? '']);

        return;
    }

    if (isset($form['delete'])) {
        delete_profile((string) $form['delete']);

        return;
    }

    // A Host picked out of ~/.ssh/config. It says how to reach the machine and
    // nothing about which database on it, so this fills in the half it knows
    // and leaves the form open on the half it does not.
    if (isset($form['from'])) {
        $alias = (string) $form['from'];
        if (in_array($alias, config_hosts(), true)) {
            state(['form' => ['ssh' => $alias], 'editing' => '']);
        }

        return;
    }

    if (isset($form['save'])) {
        $error = save_profile($form);
        state($error === ''
            ? ['form' => [], 'editing' => '']
            : ['error' => $error, 'form' => $form, 'editing' => (string) ($form['id'] ?? '')]);

        return;
    }

    // Opening a saved tunnel takes its stored fields, plus whatever secret was
    // typed just now — a passphrase is the one thing the profile cannot carry.
    if (isset($form['start'])) {
        $saved = profile((string) $form['start']);
        if (!$saved) {
            return;
        }
        $form = $saved + ['secret' => $form['secret'] ?? ''];
    } elseif (!isset($form['open'])) {
        return;
    }

    // 0, not 22: a bare host may be a ~/.ssh/config alias carrying its own Port,
    // and a -p we invented would quietly override it.
    [$sshHost, $sshPort] = split_host((string) ($form['ssh'] ?? ''), 0);
    $mode = ($form['auth'] ?? '') === 'password' ? 'password' : 'key';
    $ssh = [
        'host' => $sshHost,
        'port' => $sshPort,
        'mode' => $mode,
        'username' => trim((string) ($form['username'] ?? '')),
        'password' => (string) ($form['secret'] ?? ''),
        // A named key only means anything when keys are what we are using.
        'key' => $mode === 'key' ? key_path((string) ($form['key'] ?? '')) : '',
    ];

    $dbHost = trim((string) ($form['host'] ?? '')) ?: 'localhost';
    $dbPort = (int) ($form['port'] ?? 0);
    $wanted = (int) ($form['local'] ?? 0);

    // Redrawn either way, so the typed values come back — except the secret,
    // which is never echoed into the page.
    $keep = ['id' => $form['id'] ?? '', 'name' => $form['name'] ?? '',
        'ssh' => $form['ssh'] ?? '', 'auth' => $mode, 'key' => $form['key'] ?? '',
        'username' => $form['username'] ?? '',
        'host' => $form['host'] ?? '', 'port' => $form['port'] ?? '', 'local' => $form['local'] ?? ''];

    $error = '';
    if ($ssh['host'] === '') {
        $error = 'Name the bastion — a Host from ~/.ssh/config, or host[:port].';
    } elseif ($dbPort < 1 || $dbPort > 65535) {
        $error = 'Give the database port as the bastion sees it.';
    } elseif ($wanted !== 0 && ($wanted < 1024 || $wanted > 65535)) {
        $error = 'A local port must be between 1024 and 65535, or left blank.';
    } elseif ($mode === 'key' && ($form['key'] ?? '') !== '' && $ssh['key'] === '') {
        $error = 'No such key in the mounted ~/.ssh.';
    } elseif ($mode === 'key' && $ssh['password'] === '' && $ssh['key'] !== '' && key_encrypted($ssh['key'])) {
        // Said now rather than as "Permission denied (publickey)" in a moment.
        $error = 'That key needs a passphrase.';
    }

    if ($error === '') {
        $tunnel = open($ssh, $dbHost, $dbPort, $wanted);
        $error = $tunnel['error'];
        if ($error === '') {
            // The same bastion and the same database is the same tunnel, so a
            // second request for it gets the first one back. Say so, rather
            // than appearing to ignore the local port that was asked for.
            $note = $tunnel['reused'] && $wanted > 0 && $wanted !== $tunnel['port']
                ? 'Already open on 127.0.0.1:' . $tunnel['port'] . ' — reused, port ' . $wanted . ' not needed.'
                : '';
            state(['opened' => $tunnel['port'], 'note' => $note, 'form' => []]);

            return;
        }
    }

    state(['error' => $error, 'form' => $keep]);
}
