#!/bin/sh
# Deploy helper for the pilot server. Run from this folder as root (sudo). It holds no secrets: the worker
# reads the database password from Secrets Manager itself.
#
#   sudo ./deploy.sh init DB_ENDPOINT DB_SECRET_ARN [REGION]   write .env (non-secret settings)
#   sudo ./deploy.sh build                                     build the image (about 20 to 25 minutes)
#   sudo ./deploy.sh setup                                     one-time dashboard setup
#   sudo ./deploy.sh operator EMAIL "Full Name" owner          create a dashboard login (prints a password once)
#   sudo ./deploy.sh up                                        start web, worker and Caddy
#   sudo ./deploy.sh status | logs [service] | down
set -eu
cd "$(dirname "$0")"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo" >&2
    exit 1
fi

DC="docker compose"
cmd="${1:-}"
[ "$#" -gt 0 ] && shift

case "$cmd" in
    init)
        [ "$#" -ge 2 ] || { echo "Usage: init DB_ENDPOINT DB_SECRET_ARN [REGION]" >&2; exit 2; }
        umask 077
        {
            printf 'DB_HOST=%s\n' "$1"
            printf 'DB_SECRET_ARN=%s\n' "$2"
            printf 'AWS_REGION=%s\n' "${3:-eu-north-1}"
        } > .env
        echo "Wrote .env (endpoint and secret name only, no password)."
        ;;
    build)
        # Plain docker build: "docker compose build" needs the buildx plugin, which this server does not have.
        docker build -f Dockerfile -t "emb-ehr:${EMB_TAG:-local}" ../..
        ;;
    setup)
        $DC run --rm worker setup
        ;;
    operator)
        $DC run --rm --no-deps worker operator "$@"
        ;;
    up)
        $DC up -d
        $DC ps
        ;;
    status)
        $DC ps
        ;;
    logs)
        $DC logs --tail 50 -f "$@"
        ;;
    down)
        $DC down
        ;;
    *)
        sed -n '2,12p' "$0"
        exit 2
        ;;
esac
