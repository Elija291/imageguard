FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
 && chmod +x /usr/sbin/policy-rc.d \
 && apt-get update \
 && apt-get install -y --no-install-recommends apache2 mysql-server php libapache2-mod-php php-mysql php-curl php-gd php-mbstring ca-certificates supervisor \
 && rm -rf /var/lib/apt/lists/* /var/www/html/* \
 && a2enmod headers \
 && a2dismod -f status autoindex \
 && install -d -o www-data -g www-data -m 700 /var/lib/imageguard/sessions /var/lib/imageguard/tmp
COPY app/ /opt/imageguard/app/
COPY public/ /var/www/html/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /etc/php/8.3/apache2/conf.d/99-imageguard.ini
COPY docker/mysql.cnf /etc/mysql/mysql.conf.d/99-imageguard.cnf
COPY docker/supervisord.conf /etc/supervisor/conf.d/imageguard.conf
COPY docker/entrypoint.sh /usr/local/bin/imageguard-entrypoint
COPY docker/init.php /opt/imageguard/init.php
COPY tests/ /opt/imageguard/tests/
RUN chmod 755 /usr/local/bin/imageguard-entrypoint \
 && chown -R root:root /opt/imageguard /var/www/html \
 && find /opt/imageguard /var/www/html -type d -exec chmod 755 {} \; \
 && find /opt/imageguard /var/www/html -type f -exec chmod 644 {} \;
EXPOSE 80
VOLUME ["/var/lib/mysql"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 CMD php /opt/imageguard/app/health.php
ENTRYPOINT ["/usr/local/bin/imageguard-entrypoint"]
