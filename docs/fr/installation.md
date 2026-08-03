# Installation

[← Documentation](README.md) · [Prise en main →](prise-en-main.md)

UptimeEZ est une application PHP simple. Aucune compilation, aucun gestionnaire de paquets, aucun conteneur. Si vous
savez envoyer des fichiers par FTP et ajouter une tâche cron, vous savez l'installer.

---

## Prérequis

| | |
|---|---|
| **PHP** | 8.2 ou plus récent, en ligne de commande *et* en web. Vérifié sur 8.2, 8.3, 8.4 et 8.5 |
| **Extensions** | `curl`, `json`, `mbstring`, et `pdo_sqlite` (par défaut) ou `pdo_mysql` |
| **Droits d'écriture** | le dossier `data/`, et la racine une fois (pour créer `config.php`) |
| **Cron** | une ligne par minute : ou n'importe quel planificateur capable d'appeler une URL |
| **HTTPS sortant** | le collecteur doit pouvoir joindre les sites surveillés |

Rien d'autre. L'installeur vérifie tout cela et vous dit ce qui manque avant d'écrire quoi que ce soit.

> **À propos d'`intl`.** Si l'extension est présente, la recherche insensible aux accents utilise la normalisation
> Unicode, quelle que soit la langue. Sans elle, une table de secours couvre le latin étendu. Rien ne casse dans
> les deux cas.

---

## Trois voies d'entrée, et laquelle fait référence

| | Quand elle convient | Ce qu'elle coûte |
|---|---|---|
| **`install.php` dans un navigateur** | La référence. Mutualisé, FTP, aucun shell nécessaire | Rien. Elle montre la liste des contrôles et explique ce qui manque |
| **`php bin/installer.php`** | En SSH, pour poser plusieurs instances, ou pour ne pas exposer une adresse d'administration le temps de l'installation | Un shell |
| **`docker compose up -d`** | Votre machine, et vous préférez une commande à un transfert de fichiers | Docker, dont le produit n'a par ailleurs jamais besoin |

La première est celle que ce document décrit et d'où viennent les captures. Les deux autres existent pour des
cas précis et ne changent rien au produit : aucune étape de compilation, aucun gestionnaire de paquets, aucune
dépendance à résoudre, quelle que soit la voie.

### L'installeur en ligne de commande

```bash
php bin/installer.php --verifier                 # contrôle l'environnement, n'écrit rien
php bin/installer.php                            # interactif : il demande ce qu'il faut
UPTIMEEZ_MOT_DE_PASSE=… php bin/installer.php --url=https://surveillance.exemple.fr
php bin/installer.php --mysql --db-nom=uptimeez --db-user=uptimeez
```

Il fait exactement les mêmes contrôles d'environnement que l'installeur web, refuse d'écraser un `config.php`
existant pour la même raison (le réécrire redéfinit le mot de passe d'accès), et finit par imprimer la ligne de
cron avec le bon chemin de PHP pour cette machine. Passer le mot de passe par l'environnement le garde hors de
l'historique du shell.

### Docker, facultatif et qui le reste

```bash
docker compose up -d          # puis http://localhost:8080/install.php
PORT=8090 docker compose up -d # si le 8080 est déjà pris
```

Deux services, et le second est celui qu'on oublie : `web` sert les pages, `planificateur` fait une passe par
minute. Un seul conteneur qui sert des pages donnerait une installation qui s'ouvre, qui affiche tout en vert et
qui ne surveille rien, c'est-à-dire l'état le plus trompeur de ce produit. Le planificateur est un service
visible dont l'arrêt se voit dans `docker compose ps`, et sa sortie part dans
`docker compose logs planificateur` plutôt que dans un fichier que personne ne lit.

Un seul volume nommé porte la base **et** la configuration, par `UPTIMEEZ_CONFIG`. Sans lui, la configuration
serait écrite dans l'image, donc perdue à la première reconstruction : l'installation repartirait de zéro avec
une base intacte et un mot de passe oublié.

---

## Installation classique

```bash
git clone https://github.com/coeurduweb/uptimeez.git
cd uptimeez
chmod -R 775 data
```

Puis ouvrez `install.php` dans un navigateur :

1. Il vérifie l'environnement et affiche une liste de contrôle verte/rouge.
2. Vous choisissez un mot de passe (8 caractères minimum), stocké haché avec `password_hash()`.
3. Il écrit `config.php` et crée la base.
4. Il refuse de tourner une seconde fois : réinstaller suppose de supprimer `config.php` par FTP ou SSH d'abord.

Enfin, ajoutez la tâche cron (voir plus bas) et ouvrez les réglages pour configurer un canal d'alerte.

---

## Hébergement mutualisé (o2switch, cPanel, Plesk, OVH…)

C'est la cible principale, pas un cas dégradé.

1. Envoyez le dossier dans `public_html/uptimeez/` (ou là où vous servez vos pages).
2. Passez `data/` en `775` dans le gestionnaire de fichiers.
3. Visitez `https://votredomaine.fr/uptimeez/install.php`.
4. Dans cPanel → **Tâches cron**, fréquence **chaque minute** :

   ```
   * * * * * /usr/local/bin/php /home/VOTRECOMPTE/public_html/uptimeez/cron.php >/dev/null 2>&1
   ```

   La ligne exacte, avec le bon chemin PHP pour votre compte, est affichée dans **Réglages → Tâche planifiée** :
   copiez-la de là plutôt que de la deviner.

**Spécificités o2switch.** Le binaire PHP est en général `/usr/local/bin/php`. LiteSpeed ignore certains drapeaux
de réécriture d'`.htaccess`, raison pour laquelle UptimeEZ ne dépend d'aucune réécriture d'URL : chaque adresse est
un simple `index.php?p=…`. Rien à configurer.

**Pas de crontab du tout ?** Réglages → *Déclenchement par URL* vous donne une adresse secrète :

```
https://votredomaine.fr/uptimeez/cron.php?key=VOTRE_CLE
```

Appelez-la chaque minute depuis n'importe quel service externe (cron-job.org, EasyCron, une GitHub Action, le
crontab d'un autre serveur). Sans la bonne clé, le point d'entrée répond 403 et ne fait rien.

---

## Protéger l'installation

UptimeEZ est protégé par mot de passe et envoie `noindex, nofollow` sur chaque page. Pour une ceinture et des
bretelles :

- Placez-le sur un sous-domaine que vous ne communiquez pas, ou dans un dossier au nom peu évident.
- Ajoutez une authentification HTTP par-dessus (cPanel → *Confidentialité du répertoire*) si vous voulez.
- Gardez `config.php` hors de tout dépôt : il contient votre empreinte de mot de passe et vos URL de webhook.
- Le fichier `data/.htaccess` fourni interdit l'accès web à la base. Si votre serveur ignore `.htaccess` (nginx,
  par exemple), déplacez `data/` hors de la racine web et pointez `db.sqlite` sur le nouveau chemin.

---

## MySQL au lieu de SQLite

SQLite est le bon défaut : zéro configuration, un seul fichier, et il tient confortablement quelques centaines de
sondes sur un mutualisé. Passez à MySQL au-delà d'environ 300 vérifications par minute, ou si vous voulez la base
sur un serveur séparé.

Modifiez `config.php` :

```php
'db' => [
    'driver'  => 'mysql',
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => 'uptimeez',
    'user'    => 'uptimeez',
    'pass'    => '…',
    'charset' => 'utf8mb4',
],
```

Le schéma est créé et mis à jour automatiquement au chargement suivant. Aucune migration à lancer, aucun changement
destructif : les colonnes s'ajoutent, elles ne se suppriment jamais.

Pour transporter l'historique existant, exportez les tables SQLite et importez-les : le schéma est identique à part
les types de colonnes.

---

## Mettre à jour

```bash
git pull                    # ou : écrasez les anciens fichiers par les nouveaux
```

`config.php` et `data/` ne sont jamais touchés. Le schéma se met à jour de lui-même à la requête suivante. Puis,
pour être sûr que la nouvelle version se porte bien sur votre serveur :

```bash
php bin/selftest.php        # logique de détection, hors ligne
php bin/e2e.php             # parcours complet, instance isolée
```

Si l'un des deux signale un échec, la version précédente est toujours dans votre sauvegarde et rien dans `data/`
n'a été modifié.

---

## Désinstaller

Supprimez le dossier. C'est tout : aucun service système, aucun paquet global, aucune entrée de registre, rien en
dehors du répertoire. Si vous avez utilisé la démo, `php bin/demo.php --purge` la retire sans toucher au reste.
