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
    && ln -sf /usr/bin/php84 /usr/local/bin/php \
    && addgroup -S -g 10001 adminer \
    && adduser -S -u 10001 -G adminer -H -h /app adminer

COPY --chown=root:root php.ini /etc/php84/conf.d/99-instrument.ini

WORKDIR /app

COPY --from=upstream --chown=root:root /var/www/html/adminer.php ./adminer.php
COPY --from=upstream --chown=root:root /var/www/html/plugins ./plugins
COPY --chown=root:root src/index.php ./index.php
# One structure layer, two token sets, served as separate files — so this
# directory can be bind-mounted for live editing without a rebuild.
COPY --chown=root:root theme/ ./theme/

RUN rm -f plugins/README.md && chmod -R a-w /app

ENV ADMINER_THEME=dark \
    PHP_CLI_SERVER_WORKERS=4

USER adminer
EXPOSE 8080
STOPSIGNAL SIGINT

HEALTHCHECK --interval=30s --timeout=3s --start-period=3s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1

CMD ["php", "-S", "[::]:8080", "-t", "/app"]
