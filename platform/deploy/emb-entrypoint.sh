#!/bin/sh
# Entrypoint for the EMB EHR image. Replaces the stock startup script (which configures a single
# practice at start, changes permissions and deletes setup files) with what a multi-practice server needs.
#
#   web        Apache (OpenEMR and the dashboard)             no database root credential
#   worker     dashboard worker: creates and manages practices  fetches the root credential itself
#   setup      one-time dashboard setup (database, tables, config)
#   operator   create a dashboard login: operator EMAIL "Full Name" owner|operator|viewer
#   shell      a shell, for debugging
#
# Needs for worker and setup: DB_SECRET_ARN (Secrets Manager secret holding {"username","password"}),
# AWS_REGION, PROVISION_DB_HOST. The server's IAM role grants the read. None of these is a secret.
set -eu

APP=/var/www/localhost/htdocs/openemr
cd "$APP"

mode="${1:-web}"
[ "$#" -gt 0 ] && shift

prepare_dirs() {
    mkdir -p "$APP/sites/.provisioning" "$APP/platform/dashboard/var" /run/apache2
    chown apache:apache "$APP/sites" "$APP/sites/.provisioning" "$APP/platform/dashboard/var"
    chmod 700 "$APP/sites" "$APP/platform/dashboard/var"
}

fetch_db_secret() {
    : "${DB_SECRET_ARN:?DB_SECRET_ARN is not set}"
    : "${AWS_REGION:?AWS_REGION is not set}"
    secret="$(aws secretsmanager get-secret-value --region "$AWS_REGION" --secret-id "$DB_SECRET_ARN" \
        --query SecretString --output text)"
    PROVISION_DB_ROOT_USER="$(printf '%s' "$secret" | jq -r .username)"
    PROVISION_DB_ROOT_PASS="$(printf '%s' "$secret" | jq -r .password)"
    unset secret
    export PROVISION_DB_ROOT_USER PROVISION_DB_ROOT_PASS
    export MYSQL_HOST="${PROVISION_DB_HOST:-}"
    export OPENEMR_ENABLE_PROVISIONING=1
}

case "$mode" in
    web)
        prepare_dirs
        exec /usr/sbin/httpd -D FOREGROUND
        ;;
    worker)
        prepare_dirs
        fetch_db_secret
        exec su-exec apache php platform/dashboard/bin/worker.php "$@"
        ;;
    setup)
        prepare_dirs
        fetch_db_secret
        exec su-exec apache php platform/dashboard/bin/setup.php "$@"
        ;;
    operator)
        prepare_dirs
        exec su-exec apache php platform/dashboard/bin/create-operator.php "$@"
        ;;
    shell)
        exec sh
        ;;
    *)
        echo "Unknown mode: $mode (web, worker, setup, operator, shell)" >&2
        exit 2
        ;;
esac
