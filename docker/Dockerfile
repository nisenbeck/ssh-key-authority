FROM php:8.4-apache-trixie

COPY docker/config/apache.conf /etc/apache2/sites-available/ska.conf
RUN rm /etc/apache2/sites-enabled/*.conf && a2ensite ska.conf && a2enmod ldap authnz_ldap rewrite

COPY docker/entrypoint.sh docker/healthcheck.sh /
COPY docker/cron /var/spool/cron/crontabs/root

# Install minimal system libs required to build extensions (deterministic)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libldap2-dev \
        libsasl2-dev \
        libonig-dev \
        libxml2-dev \
        cron \
        openssh-client \
    ; \
    # Configure and build LDAP and other required PHP extensions deterministically
    docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu; \
    # Install process-control and POSIX extensions (required for syncd daemon)
    docker-php-ext-install -j"$(nproc)" pcntl posix ldap mysqli xml mbstring; \
    # cleanup build dependencies to keep image small
    apt-get purge -y --auto-remove libsasl2-dev libldap2-dev libonig-dev libxml2-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    chmod +x /entrypoint.sh /healthcheck.sh; \
    useradd --uid 999 --gid 999 --shell /bin/bash --no-create-home --home-dir /ska keys-sync; \
    mkdir -p /var/log/keys/ /var/run/keys; \
    chown keys-sync:nogroup /var/run/keys;

WORKDIR /ska
COPY . /ska/

ENTRYPOINT [ "/entrypoint.sh" ]
HEALTHCHECK --interval=30s --timeout=30s --start-period=5s --retries=3 CMD [ "/healthcheck.sh" ]