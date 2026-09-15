#!/bin/sh
# Adminer Instrument — SSH tunnels, then the web server.
#
# Adminer speaks database protocols, not SSH: there is no upstream hook for
# "connect through a bastion", and no PHP driver can be handed a tunnelled
# stream (mysqli and libpq both open their own socket). So the tunnel is opened
# beside the application, before it starts, and Adminer is pointed at the local
# end of it — exactly what you would do by hand with `ssh -L`.
#
# Declared with ADMINER_SSH_TUNNELS, one per line (commas work too):
#
#   label=<local_port>:<db_host>:<db_port>:[<ssh_user>@]<ssh_host>[:<ssh_port>][|opt=value...]
#
#   prod=13306:10.0.0.5:3306:deploy@bastion.example.com
#   stage=15432:db.internal:5432:ubuntu@1.2.3.4:2222|key=/run/secrets/stage_key
#   local=13307:localhost:3306:prod-bastion          <- a Host from ~/.ssh/config
#
# That last form is why the user and the port are optional: mount your own
# ~/.ssh at ADMINER_SSH_CONFIG_DIR and a Host alias brings its own HostName,
# User, Port and IdentityFile, exactly as it would from a shell.
#
# `db_host` is resolved on the far side of the SSH connection, so it may be a
# name only the bastion knows. Every local port binds to 127.0.0.1, so a tunnel
# is reachable from this container and nowhere else.
#
# Per-tunnel options:
#   key=<path>      private key for this tunnel     (default: ADMINER_SSH_KEY)
#   pass=<VAR>      NAME of an environment variable holding the SSH password —
#                   never the password itself, so it stays out of the spec, out
#                   of `docker inspect`'s command line, and out of the log.
#
# A missing bastion costs you that one tunnel: the others still come up, and so
# does Adminer. Nothing here blocks the web server from starting.

set -eu

SSH_HOME="${ADMINER_SSH_HOME:-/tmp/adminer-ssh}"
PORTS_FILE="$SSH_HOME/ports"

log() { printf '%s adminer-ssh: %s\n' "$(date '+%Y-%m-%dT%H:%M:%S')" "$*" >&2; }

# Open TCP port on the loopback? php is the one binary guaranteed to be here,
# and fsockopen is the same connect() Adminer will make a moment later.
port_open() {
    php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1], $e, $s, 2) ? 0 : 1);' "$1" >/dev/null 2>&1
}

# --- healthcheck -------------------------------------------------------------
# The web server, plus every tunnel that was asked for. A container whose
# tunnels have died is not serving what it was configured to serve.
if [ "${1:-}" = "healthcheck" ]; then
    wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1
    [ -f "$PORTS_FILE" ] || exit 0
    while read -r port label; do
        [ -n "$port" ] || continue
        port_open "$port" || { log "healthcheck: tunnel '$label' (port $port) is down"; exit 1; }
    done < "$PORTS_FILE"
    exit 0
fi

# --- no tunnels declared: nothing to do --------------------------------------
if [ -z "${ADMINER_SSH_TUNNELS:-}" ]; then
    exec "$@"
fi

# --- a writable HOME ---------------------------------------------------------
# /app is read-only by design and the image runs as uid 10001, so ssh needs
# somewhere else to keep its known_hosts and the key copies below. /tmp is
# tmpfs: the keys never touch a disk and never outlive the container.
rm -rf "$SSH_HOME"
mkdir -p "$SSH_HOME/keys" "$SSH_HOME/.ssh"
chmod 700 "$SSH_HOME" "$SSH_HOME/keys" "$SSH_HOME/.ssh"
HOME="$SSH_HOME"
export HOME
: > "$PORTS_FILE"

# Stage a mounted ~/.ssh, for the same reason the keys below are copied: ssh
# refuses a private key carrying a bind mount's permissions, and a read-only
# mount is nowhere to append a host key. Copying rather than linking is also
# what makes `IdentityFile ~/.ssh/id_rsa` in the config resolve — ~ is here now.
CONFIG_DIR="${ADMINER_SSH_CONFIG_DIR:-/ssh}"
SSH_CONFIG=''
if [ -d "$CONFIG_DIR" ]; then
    for file in "$CONFIG_DIR"/*; do
        [ -f "$file" ] || continue
        # A private key is 0600 and belongs to whoever made it, so a mounted
        # ~/.ssh is unreadable to a container running as anyone else. Say so:
        # skipping it quietly turns into "no such identity" much later.
        if [ ! -r "$file" ]; then
            log "warning: cannot read $file — run this container as the uid that owns it"
            continue
        fi
        cp "$file" "$SSH_HOME/.ssh/" 2>/dev/null || true
    done
    chmod 600 "$SSH_HOME"/.ssh/* 2>/dev/null || true
    if [ -r "$SSH_HOME/.ssh/config" ]; then
        # ssh finds the per-user config through getpwuid(), not $HOME, and
        # expands `~` the same way — so a mounted config would be ignored and
        # its `IdentityFile ~/.ssh/id_rsa` would point at the image's read-only
        # /app. Hence -F below, and this rewrite.
        sed -i "s|~/|$SSH_HOME/|g; s|%d/|$SSH_HOME/|g" "$SSH_HOME/.ssh/config"
    fi
    # And for a host the config does not name, ssh's built-in default
    # identities point at that same unusable home. Name the staged keys.
    for key in "$SSH_HOME"/.ssh/*; do
        case $key in *.pub|*/config|*/known_hosts|*/authorized_keys) continue ;; esac
        [ -f "$key" ] && grep -ql 'PRIVATE KEY' "$key" 2>/dev/null || continue
        [ -s "$SSH_HOME/.ssh/config" ] || printf '\n' >> "$SSH_HOME/.ssh/config"
        printf 'Host *\n  IdentityFile %s\n' "$key" >> "$SSH_HOME/.ssh/config"
    done
    if [ -r "$SSH_HOME/.ssh/config" ]; then
        chmod 600 "$SSH_HOME/.ssh/config"
        SSH_CONFIG="$SSH_HOME/.ssh/config"
    fi
    log "staged $CONFIG_DIR into the tunnel's ~/.ssh"
fi

KNOWN_HOSTS="${ADMINER_SSH_KNOWN_HOSTS:-}"
if [ -n "$KNOWN_HOSTS" ] && [ -r "$KNOWN_HOSTS" ]; then
    STRICT=yes
else
    # Trust-on-first-use. Enough to get a laptop talking to its own bastion,
    # not enough to detect a man in the middle on the first connection — mount
    # a known_hosts file and set ADMINER_SSH_KNOWN_HOSTS to close that gap.
    [ -z "$KNOWN_HOSTS" ] || log "warning: ADMINER_SSH_KNOWN_HOSTS='$KNOWN_HOSTS' is not readable"
    KNOWN_HOSTS="$SSH_HOME/.ssh/known_hosts"
    if [ -s "$KNOWN_HOSTS" ]; then
        STRICT=yes                 # staged from your own ~/.ssh: already vouched for
    else
        log "warning: no known_hosts — host keys are accepted on first sight (StrictHostKeyChecking=accept-new)"
        : > "$KNOWN_HOSTS"
        STRICT=accept-new
    fi
fi

# ssh refuses a key it considers world-readable, and a bind-mounted key carries
# the host's permissions and the host's owner. Copying it into tmpfs at 0600 is
# what makes `-v ./id_ed25519:/run/secrets/ssh_key:ro` work at all.
install_key() {
    _src=$1 _label=$2
    if [ ! -r "$_src" ]; then
        log "tunnel '$_label': key '$_src' is not readable"
        return 1
    fi
    _dest="$SSH_HOME/keys/$_label"
    cp "$_src" "$_dest" && chmod 600 "$_dest" || return 1
    printf '%s' "$_dest"
}

# One supervised tunnel. ssh is run in the foreground and restarted when it
# exits, which is the whole of what autossh would add here: a dropped VPN, a
# rebooted bastion or an idle-timeout kill all repair themselves within
# ADMINER_SSH_RETRY seconds instead of needing the container restarted.
#
# The keepalives are what make that happen: a peer that stops answering is
# nothing a TCP socket notices on its own, so without them ssh would sit on a
# dead connection — holding the local port open, and accepting connections it
# can no longer forward — until something tried to use it. Three missed probes
# at ten seconds tears the tunnel down about thirty seconds after the far end
# goes away, which is also when the healthcheck can first see it.
supervise() {
    _label=$1 _lport=$2 _dbhost=$3 _dbport=$4 _target=$5 _sshport=$6 _key=$7 _passvar=$8
    while :; do
        set -- \
            -N -T \
            -o ExitOnForwardFailure=yes \
            -o ServerAliveInterval=10 \
            -o ServerAliveCountMax=3 \
            -o ConnectTimeout=10 \
            -o StrictHostKeyChecking="$STRICT" \
            -o UserKnownHostsFile="$KNOWN_HOSTS" \
            -L "127.0.0.1:$_lport:$_dbhost:$_dbport"
        [ -z "$SSH_CONFIG" ] || set -- "$@" -F "$SSH_CONFIG"
        # Only when it was written down: a bare alias must keep the Port that
        # ~/.ssh/config gives it rather than have 22 forced on by us.
        [ -z "$_sshport" ] || set -- "$@" -p "$_sshport"
        [ -z "$_key" ] || set -- "$@" -o IdentitiesOnly=yes -i "$_key"

        if [ -n "$_passvar" ]; then
            # sshpass drives the password prompt through a pty. BatchMode would
            # suppress that prompt, so it is deliberately absent here.
            SSHPASS=$(printenv "$_passvar" || true)
            export SSHPASS
            [ -n "$SSHPASS" ] || log "tunnel '$_label': \$$_passvar is empty"
            sshpass -e ssh -o PreferredAuthentications=password,keyboard-interactive \
                -o PubkeyAuthentication=no ${ADMINER_SSH_OPTS:-} "$@" "$_target" || true
            unset SSHPASS
        else
            ssh -o BatchMode=yes ${ADMINER_SSH_OPTS:-} "$@" "$_target" || true
        fi

        log "tunnel '$_label' closed, reconnecting in ${ADMINER_SSH_RETRY:-5}s"
        sleep "${ADMINER_SSH_RETRY:-5}"
    done
}

# --- parse the declarations --------------------------------------------------
printf '%s\n' "$ADMINER_SSH_TUNNELS" | tr ',' '\n' | while IFS= read -r line; do
    line=${line%%#*}                                    # trailing comment
    line=$(printf '%s' "$line" | tr -d '[:space:]')     # and all whitespace
    [ -n "$line" ] || continue

    case $line in *\|*) opts=${line#*|}; line=${line%%|*} ;; *) opts='' ;; esac
    case $line in *=*) label=${line%%=*}; spec=${line#*=} ;; *) label=''; spec=$line ;; esac

    IFS=: read -r lport dbhost dbport target sshport <<SPEC
$spec
SPEC
    if [ -z "$lport" ] || [ -z "$dbhost" ] || [ -z "$dbport" ] || [ -z "$target" ]; then
        log "ignoring '$spec': expected local_port:db_host:db_port:user@ssh_host[:ssh_port]"
        continue
    fi
    [ -n "$label" ] || label=$lport

    key=${ADMINER_SSH_KEY:-} passvar=''
    OLDIFS=$IFS; IFS='|'
    for opt in $opts; do
        case $opt in
            key=*)  key=${opt#key=} ;;
            pass=*) passvar=${opt#pass=} ;;
            '')     ;;
            *)      log "tunnel '$label': unknown option '$opt'" ;;
        esac
    done
    IFS=$OLDIFS

    if [ -n "$key" ]; then
        key=$(install_key "$key" "$label") || { log "tunnel '$label': skipped"; continue; }
    fi

    supervise "$label" "$lport" "$dbhost" "$dbport" "$target" "$sshport" "$key" "$passvar" &
    printf '%s %s\n' "$lport" "$label" >> "$PORTS_FILE"
    log "tunnel '$label': 127.0.0.1:$lport -> $dbhost:$dbport via $target${sshport:+:$sshport}"
done

# --- give the tunnels a moment before the login page offers them -------------
wait_for=${ADMINER_SSH_WAIT:-15}
while [ "$wait_for" -gt 0 ]; do
    pending=0
    while read -r port label; do
        [ -n "$port" ] || continue
        port_open "$port" || pending=$((pending + 1))
    done < "$PORTS_FILE"
    [ "$pending" -gt 0 ] || break
    sleep 1
    wait_for=$((wait_for - 1))
done

while read -r port label; do
    [ -n "$port" ] || continue
    port_open "$port" \
        && log "tunnel '$label' is up on 127.0.0.1:$port" \
        || log "tunnel '$label' did not come up — retrying in the background"
done < "$PORTS_FILE"

exec "$@"
