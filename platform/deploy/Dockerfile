# syntax=docker/dockerfile:1
#
# Production image: OpenEMR with our code, dashboard and worker. Dependencies and the frontend are built
# here, so a container starts in seconds (the stock Flex image builds them on first start, about 20 minutes).
#
# Build from the repo root:
#   docker build -f platform/deploy/Dockerfile -t emb-ehr:local .
#
# No secret goes into this image. The database password is fetched at start by the worker, from
# Secrets Manager, using the server's IAM role (see emb-entrypoint.sh).
FROM openemr/openemr:flex

ARG APP=/var/www/localhost/htdocs/openemr

# aws-cli and jq: the worker reads the database secret at start.
RUN apk add --no-cache aws-cli jq

COPY --chown=apache:apache . ${APP}
WORKDIR ${APP}

# PHP dependencies (production only). Retried because GitHub downloads sometimes fail.
RUN for i in 1 2 3 4 5; do \
        composer install --no-dev --no-interaction --prefer-dist && exit 0; \
        echo "composer install failed (attempt $i), retrying in 15s"; sleep 15; \
    done; exit 1

# Frontend: install, build the themes, then drop the build tools. CCDA service is left out (not used in Phase 1).
RUN npm install && npm run build

# Trim unused files, then optimise the class loader (same steps as the stock image).
RUN composer global require phing/phing \
    && /root/.composer/vendor/bin/phing vendor-clean \
    && /root/.composer/vendor/bin/phing assets-clean \
    && composer global remove phing/phing \
    && composer dump-autoload --optimize --apcu \
    && rm -rf node_modules /root/.npm /root/.composer/cache

# Setup scripts must not be reachable from the web. The stock startup script deletes them; we skip that
# script, so move them out of the web root. The upgrade tool copies them back for one run (see README).
RUN mkdir -p /opt/openemr-setup \
    && for f in admin.php acl_upgrade.php setup.php sql_patch.php sql_upgrade.php ippf_upgrade.php; do \
         [ -f "${APP}/$f" ] && mv "${APP}/$f" /opt/openemr-setup/ || true; \
       done \
    && rm -f /var/www/localhost/htdocs/auto_configure.php

# Writable by the web server user: practice folders (sites, a volume at run time) and the dashboard's own data.
RUN mkdir -p ${APP}/platform/dashboard/var ${APP}/sites/.provisioning /run/apache2 \
    && chown -R apache:apache ${APP} \
    && chmod 700 ${APP}/sites ${APP}/platform/dashboard/var

# Apache errors to the container log.
RUN printf 'ErrorLog /dev/stderr\n' > /etc/apache2/conf.d/zz-emb-log.conf

COPY platform/deploy/emb-entrypoint.sh /usr/local/bin/emb-entrypoint.sh
RUN chmod 755 /usr/local/bin/emb-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/emb-entrypoint.sh"]
CMD ["web"]
EXPOSE 80
