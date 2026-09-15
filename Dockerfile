# syntax=docker/dockerfile:1

# Adminer Instrument
# ------------------
# Upstream Adminer, restructured: Alpine + php84 straight from apk instead of
# the docker-php toolchain, only the database drivers anyone actually asks for,
# a theme served through the css() hook so nothing is written at runtime, and a
# multi-worker built-in server instead of the single-threaded default.

ARG ADMINER_VERSION=5.4.1
ARG ALPINE_VERSION=3.22

# --- upstream source ---------------------------------------------------------
# Taken from the official image so the PHP payload is byte-for-byte the release
# artifact, with no build-time network fetch of our own.
FROM adminer:${ADMINER_VERSION} AS upstream

# --- runtime -----------------------------------------------------------------
FROM alpine:${ALPINE_VERSION}

ARG ADMINER_VERSION
ARG TARGETPLATFORM

# Who the container runs as. 10001 is a service account and the right default
# for a published image — but reading a mounted ~/.ssh means being the uid that
# owns those 0600 keys, and `ssh` refuses to start for a uid with no entry in
# /etc/passwd. So it is a build argument rather than a `user:` override:
#   HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d --build
ARG HOST_UID=10001
ARG HOST_GID=10001

LABEL org.opencontainers.image.title="Adminer Instrument" \
      org.opencontainers.image.description="Adminer ${ADMINER_VERSION} with the Instrument theme, a curated plugin set, and a smaller multi-worker runtime." \
      org.opencontainers.image.version="${ADMINER_VERSION}" \
      org.opencontainers.image.licenses="Apache-2.0 OR GPL-2.0-only" \
      org.opencontainers.image.source="https://github.com/vrana/adminer"

RUN apk add --no-cache \
        php84 \
        php84-ctype \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-opcache \
        php84-openssl \
        php84-session \
        # drivers: PostgreSQL (native + PDO), MySQL/MariaDB, SQLite, MongoDB
        php84-pdo \
        php84-pgsql \
        php84-pdo_pgsql \
        php84-mysqli \
        php84-mysqlnd \
        php84-pdo_mysql \
        php84-sqlite3 \
        php84-pdo_sqlite \
        php84-pecl-mongodb \
        # export formats
        php84-bz2 \
        php84-zip \
        # SSH tunnels to databases behind a bastion (see src/entrypoint.sh).
        # ~2 MB, and dormant unless ADMINER_SSH_TUNNELS is set.
        openssh-client \
        sshpass \
    && ln -sf /usr/bin/php84 /usr/local/bin/php \
    && addgroup -S -g "${HOST_GID}" adminer \
    && adduser -S -u "${HOST_UID}" -G adminer -H -h /app adminer

COPY --chown=root:root php.ini /etc/php84/conf.d/99-instrument.ini

WORKDIR /app

COPY --from=upstream --chown=root:root /var/www/html/adminer.php ./adminer.php
COPY --from=upstream --chown=root:root /var/www/html/plugins ./plugins
# Upstream ships the Redis driver only from 6.0.x; it talks RESP over fsockopen
# and needs nothing from PHP, so it runs against this core unchanged.
COPY --chown=root:root src/plugins/drivers/redis.php ./plugins/drivers/redis.php
COPY --chown=root:root src/index.php ./index.php
COPY --chown=root:root src/ssh.php ./ssh.php
COPY --chown=root:root --chmod=755 src/entrypoint.sh ./entrypoint.sh
# One structure layer, two token sets, served as separate files — so this
# directory can be bind-mounted for live editing without a rebuild.
COPY --chown=root:root theme/ ./theme/

RUN rm -f plugins/README.md && chmod -R a-w /app

# Where saved tunnels go. Empty in the image and left out of the read-only
# sweep above: mount a volume here and the tunnel card can write; leave it
# unmounted and the card simply does not offer to save.
#
# 1777 rather than 0700 because reading a mounted ~/.ssh means running this
# container as the uid that owns those keys, and a named volume keeps whatever
# ownership the image gave it. What lands here is a list of bastions, databases
# and ports — no keys and no passwords, by design — so it is a directory to
# share, not a secret to guard.
RUN install -d -o adminer -g adminer -m 1777 /data
VOLUME /data

ENV ADMINER_THEME=dark \
    PHP_CLI_SERVER_WORKERS=4

USER ${HOST_UID}:${HOST_GID}
EXPOSE 8080
STOPSIGNAL SIGINT

# Checks the declared SSH tunnels as well as the web server: a container whose
# bastion has gone away is not serving what it was configured to serve.
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD ["/app/entrypoint.sh", "healthcheck"]

ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["php", "-S", "[::]:8080", "-t", "/app"]
