#!/usr/bin/env bash
# Local WSL only, run as root after installing PostgreSQL16/PostGIS and Redis.
# Refuses to reuse an existing project cluster; test credentials are local-only.
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { printf 'Run as root inside WSL\n' >&2; exit 1; }
if pg_lsclusters --no-header | awk '{print $2}' | grep -qx kirh_geo; then
    printf 'kirh_geo cluster already exists; leaving it unchanged\n'
    exit 0
fi
if ss -ltn | grep -Eq ':(15432|16379) '; then
    printf 'A test port is occupied; refusing to change services\n' >&2
    exit 1
fi
pg_createcluster 16 kirh_geo --port 15432 --start
runuser -u postgres -- psql -p 15432 -v ON_ERROR_STOP=1 <<'SQL'
CREATE ROLE kirh_test LOGIN PASSWORD 'kirh-local-test-only' CREATEDB;
CREATE DATABASE kirh_geo_test OWNER kirh_test;
\connect kirh_geo_test
CREATE EXTENSION postgis;
CREATE DATABASE kirh_geo_dev OWNER kirh_test;
\connect kirh_geo_dev
CREATE EXTENSION postgis;
SQL
install -d -o redis -g redis -m 750 /var/lib/redis/kirh-geo-test
runuser -u redis -- redis-server --port 16379 --bind 127.0.0.1 --daemonize yes \
    --dir /var/lib/redis/kirh-geo-test --pidfile /var/lib/redis/kirh-geo-test/redis.pid \
    --logfile /var/lib/redis/kirh-geo-test/redis.log --save '' --appendonly no
PGPASSWORD=kirh-local-test-only psql -h 127.0.0.1 -p 15432 -U kirh_test -d kirh_geo_test -c 'SELECT PostGIS_Version();'
redis-cli -p 16379 ping
