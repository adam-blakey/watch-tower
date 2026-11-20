ARG PHP_IMAGE=serversideup/php:8.4-frankenphp-trixie-v5.0.2@sha256:a535961aa62a118860292b3b04f689ee56a07f47938a614660e67fe98ff6853f
ARG NODE_IMAGE=node:24-bookworm-slim

# ---------------------------------------------------------------------------
# Node.js source
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------
# Build stage: PHP dependencies and front-end assets.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS build

USER root
WORKDIR /var/www/html

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan storage:link \
    && npm run build

RUN mkdir /dist \
    && cp -a --parents \
        app bootstrap config database public resources/views routes storage \
        artisan composer.json composer.lock \
        /dist/

# ---------------------------------------------------------------------------
# Runtime stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime

USER root

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

COPY --chmod=755 <<'EOF' /etc/entrypoint.d/40-watch-tower-storage.sh
#!/bin/sh
: "${APP_BASE_DIR:=/var/www/html}"

prepare_storage() {
    mkdir -p \
        "$APP_BASE_DIR/storage/app/public" \
        "$APP_BASE_DIR/storage/app/private" \
        "$APP_BASE_DIR/storage/framework/cache/data" \
        "$APP_BASE_DIR/storage/framework/sessions" \
        "$APP_BASE_DIR/storage/framework/views" \
        "$APP_BASE_DIR/storage/logs" || return 1

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ "$DB_DATABASE" != ":memory:" ]; then
        watch_tower_db_dir="$(dirname "$DB_DATABASE")"
        for watch_tower_db in \
            "$DB_DATABASE" \
            "${DB_TELEMETRY_DATABASE:-$watch_tower_db_dir/telemetry.sqlite}" \
            "${DB_LOGS_DATABASE:-$watch_tower_db_dir/logs.sqlite}"; do
            [ "$watch_tower_db" = ":memory:" ] && continue
            mkdir -p "$(dirname "$watch_tower_db")" && touch "$watch_tower_db" || return 1
        done
    fi
}

if ! prepare_storage; then
    echo "Cannot write to the storage directory or SQLite file. Make the mounted volume writable by UID 33 (www-data)." >&2
    exit 1
fi
EOF

COPY --chmod=644 <<'EOF' /etc/supervisor/watch-tower.conf
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid
childlogdir=/tmp

[unix_http_server]
file=/tmp/supervisor.sock
chmod=0700
username=watch-tower
password=watch-tower

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///tmp/supervisor.sock
username=watch-tower
password=watch-tower

[group:watch-tower]
programs=web,queue-worker,scheduler
priority=100

[program:web]
command=frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
directory=/var/www/html
priority=10
autostart=true
autorestart=true
startsecs=3
startretries=3
stopsignal=TERM
stopwaitsecs=20
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-worker]
command=sh -c 'until php /var/www/html/artisan migrate:status --pending=1 >/dev/null 2>&1; do sleep 10; done; exec php /var/www/html/artisan queue:work --tries=3 --sleep=3 --max-time=3600 --memory=128'
directory=/var/www/html
priority=20
autostart=%(ENV_RUN_QUEUE_WORKER)s
autorestart=true
startsecs=5
startretries=100
stopsignal=TERM
stopwaitsecs=85
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:scheduler]
command=php /var/www/html/artisan schedule:work --whisper
directory=/var/www/html
priority=30
autostart=%(ENV_RUN_SCHEDULER)s
autorestart=true
startsecs=5
startretries=100
stopsignal=TERM
stopwaitsecs=20
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[eventlistener:exit-on-fatal]
command=/usr/local/bin/watch-tower-exit-on-fatal
events=PROCESS_STATE_FATAL
priority=1
autorestart=true
startretries=100
stdout_logfile=NONE
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

COPY --chmod=755 <<'EOF' /usr/local/bin/watch-tower-exit-on-fatal
#!/bin/sh
while :; do
    printf 'READY\n'
    IFS= read -r header || exit 0
    payload=$(head -c "${header##*len:}")
    case "$payload" in
        "processname:web "*)
            echo "watch-tower: supervisord gave up on the web server ($payload). Stopping the container." >&2
            kill -TERM "$PPID"
            ;;
        *)
            echo "watch-tower: supervisord gave up on a program ($payload). The container keeps running." >&2
            ;;
    esac
    printf 'RESULT 2\nOK'
done
EOF

COPY --chmod=644 <<'EOF' /etc/frankenphp/caddyfile-server.d/watch-tower.caddyfile
@cross-origin-assets path *.svg *.svgz *.ttf *.ttc *.otf *.eot *.woff *.woff2
header @cross-origin-assets -Access-Control-Allow-Origin

header {
	?Cross-Origin-Resource-Policy "same-origin"
	?Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
}
EOF

USER www-data
WORKDIR /var/www/html

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database/database.sqlite \
    DB_JOURNAL_MODE=wal \
    DB_BUSY_TIMEOUT=5000 \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    TELESCOPE_ENABLED=false \
    RUN_QUEUE_WORKER=true \
    RUN_SCHEDULER=true

ENV TRUSTED_PROXY=off

COPY --from=build --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=build --chown=www-data:www-data /dist ./

EXPOSE 8080

CMD ["supervisord", "-n", "-c", "/etc/supervisor/watch-tower.conf"]
