# UptimeEZ: an optional image. The third of the three ways in.
#
# ------------------------------------------------------------------------------
# THIS FILE DOES NOT CHANGE WHAT THE PRODUCT PROMISES
# ------------------------------------------------------------------------------
#
# UptimeEZ needs neither Docker nor Composer nor Node: that is written everywhere and it
# stays true. This image exists for people who have a machine of their own and prefer one
# command to a file transfer. The reference way in is still `install.php` in a browser, on
# plain shared hosting if that is what you have.
#
# ------------------------------------------------------------------------------
# WHAT IT CONTAINS, AND NOTHING MORE
# ------------------------------------------------------------------------------
#
# PHP, Apache, and the five extensions the installer requires. No process manager, no cron
# daemon: scheduling is a SECOND service, declared in compose.yml, whose loop reads in
# three lines. A cron inside a container is the first thing to fail silently, because its
# logs go somewhere other than the standard output Docker shows you.
FROM php:8.4-apache

# THE EXTENSIONS, AND WHY EACH ONE. The list is exactly the one bin/installer.php checks:
# two lists that drift apart would produce an image where the installer refuses to work,
# and the message would arrive after the build.
#   curl     : every request the collector makes
#   pdo_*    : SQLite by default, MySQL optionally
#   mbstring : the text of the analysed pages
#   intl     : optional, it improves accent-insensitive search
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl; \
    rm -rf /var/lib/apt/lists/*

# Apache serves the application folder and NOTHING above it: data/ is protected there by
# its own .htaccess, which assumes overrides are allowed.
RUN a2enmod rewrite && \
    printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
      > /etc/apache2/conf-available/uptimeez.conf && \
    a2enconf uptimeez

WORKDIR /var/www/html
COPY . /var/www/html

# THE DATA FOLDER BELONGS TO APACHE, and config.php must be creatable: without that right,
# the web installer stops on "root not writable", which is the very first screen and gives
# the impression that the image is broken.
RUN mkdir -p /var/www/html/data && \
    chown -R www-data:www-data /var/www/html/data /var/www/html && \
    chmod 775 /var/www/html/data

EXPOSE 80
