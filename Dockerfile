# UptimeEZ : image optionnelle. La troisième des trois voies d'installation.
#
# ------------------------------------------------------------------------------
# CE FICHIER NE CHANGE PAS LA PROMESSE DU PRODUIT
# ------------------------------------------------------------------------------
#
# UptimeEZ n'a besoin ni de Docker ni de Composer ni de Node : c'est écrit partout et ça
# reste vrai. Cette image existe pour qui a une machine à lui et préfère une commande à un
# transfert de fichiers. La voie de référence reste `install.php` dans un navigateur, sur
# un hébergement mutualisé s'il le faut.
#
# ------------------------------------------------------------------------------
# CE QU'ELLE CONTIENT, ET RIEN DE PLUS
# ------------------------------------------------------------------------------
#
# PHP, Apache, et les cinq extensions que l'installeur exige. Pas de gestionnaire de
# processus, pas de démon cron : la planification est un SECOND service, déclaré dans
# compose.yml, dont on lit la boucle en trois lignes. Un cron dans un conteneur est la
# première chose qui échoue en silence, parce que ses journaux vont ailleurs que la sortie
# standard que Docker montre.
FROM php:8.4-apache

# LES EXTENSIONS, ET POURQUOI CHACUNE. La liste est exactement celle que bin/installer.php
# vérifie : deux listes qui divergent produiraient une image où l'installeur refuse de
# travailler, et le message arriverait après la construction.
#   curl     : toutes les requêtes du collecteur
#   pdo_*    : SQLite par défaut, MySQL en option
#   mbstring : le texte des pages analysées
#   intl     : facultatif, il améliore la recherche insensible aux accents
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl; \
    rm -rf /var/lib/apt/lists/*

# Apache sert le dossier de l'application, et RIEN au-dessus : data/ y est protégé par son
# .htaccess, ce qui suppose que les surcharges soient autorisées.
RUN a2enmod rewrite && \
    printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
      > /etc/apache2/conf-available/uptimeez.conf && \
    a2enconf uptimeez

WORKDIR /var/www/html
COPY . /var/www/html

# LE DOSSIER DE DONNÉES APPARTIENT À APACHE, et config.php doit être créable : sans ce
# droit, l'installeur web s'arrête sur « racine non accessible en écriture », ce qui est le
# tout premier écran et donne l'impression que l'image est cassée.
RUN mkdir -p /var/www/html/data && \
    chown -R www-data:www-data /var/www/html/data /var/www/html && \
    chmod 775 /var/www/html/data

EXPOSE 80
