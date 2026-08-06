# Installation

[← Documentation](README.md) · [Getting started →](getting-started.md)

UptimeEZ is a plain PHP application. There is no build step, no package manager and no container. If you can
upload files by FTP and add a cron entry, you can run it.

---

## Requirements

| | |
|---|---|
| **PHP** | 8.2 or newer, CLI *and* web. Verified on 8.2, 8.3, 8.4 and 8.5 |
| **Extensions** | `curl`, `json`, `mbstring`, and `pdo_sqlite` (default) or `pdo_mysql` |
| **Write access** | the `data/` folder, and the root folder once (to create `config.php`) |
| **Cron** | one entry per minute : or any external scheduler able to call a URL |
| **Outgoing HTTPS** | the collector needs to reach the sites you monitor |

Nothing else. The installer checks all of it and tells you what is missing before writing anything.

> **A note on `intl`.** If the extension is present, accent-insensitive search uses Unicode normalisation for
> every language. Without it, a built-in fallback table covers extended Latin. Nothing breaks either way.

---

## Three ways in, and which one is the reference

| | When it fits | What it costs |
|---|---|---|
| **`install.php` in a browser** | The reference. Shared hosting, FTP, no shell needed | Nothing. It shows the environment checklist and explains what is missing |
| **`php bin/installer.php`** | Over SSH, or when you set up several instances, or when you would rather not expose an admin URL while installing | A shell |
| **`docker compose up -d`** | Your own machine, and you prefer one command to a file transfer | Docker, which the product otherwise never needs |

The first is the one this document describes and the one the screenshots come from. The other two exist for
specific situations and change nothing about the product: no build step, no package manager, no dependency to
resolve, whichever you pick.

### The command-line installer

```bash
php bin/installer.php --verifier                 # check the environment, write nothing
php bin/installer.php                            # interactive: it asks for what it needs
UPTIMEEZ_MOT_DE_PASSE=… php bin/installer.php --url=https://monitoring.example.com
php bin/installer.php --mysql --db-nom=uptimeez --db-user=uptimeez
```

It runs exactly the same environment checks as the web installer, refuses to overwrite an existing
`config.php` for the same reason (rewriting it redefines the access password), and ends by printing the cron
line with the right PHP path for this machine. Passing the password through the environment keeps it out of your
shell history.

### Docker, which is optional and stays optional

```bash
docker compose up -d          # then open http://localhost:8080/install.php
PORT=8090 docker compose up -d # if 8080 is taken
```

Two services, and the second is the one people forget: `web` serves the pages, `planificateur` runs one pass a
minute. A single container serving pages would give you an installation that opens, shows everything green, and
monitors nothing, which is the most misleading state this product has. The scheduler is a visible service whose
stopping shows up in `docker compose ps`, and its output goes to `docker compose logs planificateur` rather
than to a file nobody reads.

One named volume holds the database **and** the configuration, through `UPTIMEEZ_CONFIG`. Without that, the
configuration would be written inside the image and lost on the first rebuild: the installation would start
over with an intact database and a forgotten password.

---

## Standard installation

```bash
git clone https://github.com/coeurduweb/uptimeez.git
cd uptimeez
chmod -R 775 data
```

Then open `install.php` in a browser:

1. It verifies the environment and shows a green/red checklist.
2. You choose a password (8 characters minimum). It is stored hashed with `password_hash()`.
3. It writes `config.php` and creates the database.
4. It refuses to run a second time : reinstalling means deleting `config.php` by FTP or SSH first.

Finally, add the cron entry (see below) and open the settings screen to configure one alert channel.

---

## Shared hosting (o2switch, cPanel, Plesk, OVH…)

This is the primary target, not an afterthought.

1. Upload the folder into `public_html/uptimeez/` (or wherever you serve from).
2. Set `data/` to `775` in the file manager.
3. Visit `https://yourdomain.com/uptimeez/install.php`.
4. In cPanel → **Cron jobs**, frequency **every minute**:

   ```
   * * * * * /usr/local/bin/php /home/YOURACCOUNT/public_html/uptimeez/cron.php >/dev/null 2>&1
   ```

   The exact line, with the right PHP path for your account, is displayed in **Settings → Scheduled task** , 
   copy it from there rather than guessing.

**o2switch specifics.** The PHP binary is usually `/usr/local/bin/php`. LiteSpeed ignores some `.htaccess`
rewrite flags, which is why UptimeEZ never relies on URL rewriting: every URL is a plain
`index.php?p=…`. Nothing to configure.

**`install.php` answers 403 and you are sure the file is there.** Seen on 2026-08-04 while
installing into a subdirectory of a WordPress site: the WAF, or a security plugin, refuses any
request whose path ends in `install.php`, because that name is a known attack signature. The
file is fine, the request never reaches it.

Do not fight the rule, take the other door:

```
php bin/installer.php
```

It asks the same questions, runs the same environment checks, and writes the same
`config.php`. Nothing about the installation differs afterwards. If SSH is not available
either, rename `install.php` to something the filter ignores, run it once, and delete it: it
refuses to run a second time anyway.

**No crontab at all?** Settings → *Trigger over URL* gives you a secret URL:

```
https://yourdomain.com/uptimeez/cron.php?key=YOUR_KEY
```

Call it every minute from any external scheduler (cron-job.org, EasyCron, a GitHub Action, another server's
crontab). Without the correct key the endpoint answers 403 and does nothing.

---

## Protecting the installation

UptimeEZ is password-protected and sends `noindex, nofollow` on every page. For a belt-and-braces setup:

- Put it on a subdomain you do not advertise, or in a folder with a non-obvious name.
- Add HTTP authentication on top (cPanel → *Directory Privacy*) if you like.
- Keep `config.php` out of version control : it holds your password hash and your webhook URLs.
- The bundled `data/.htaccess` denies web access to the database. If your server ignores `.htaccess`
  (nginx, for instance), move `data/` outside the web root and point `db.sqlite` at the new path.

---

## MySQL instead of SQLite

SQLite is the right default: zero configuration, one file, and it comfortably handles a few hundred monitors on
shared hosting. Switch to MySQL when you are past roughly 300 checks per minute, or when you want the database
on a separate server.

Edit `config.php`:

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

The schema is created and upgraded automatically on the next page load. There is no migration to run and no
destructive change: new columns are added, existing ones are never dropped.

To move existing history across, export the SQLite tables and import them : the schema is identical apart from
column types.

---

## Upgrading

```bash
git pull                    # or: upload the new files over the old ones
```

`config.php` and `data/` are never touched. The database schema upgrades itself on the next request. Then, to be
sure the new version is happy on your server:

```bash
php bin/selftest.php        # detection logic, offline
php bin/e2e.php             # full user journey, isolated instance
```

If either reports a failure, the previous version is still in your backup and nothing in `data/` was modified.

---

## Uninstalling

Delete the folder. That is all: no system service, no global package, no registry entry, nothing outside the
directory. If you used the demo data, `php bin/demo.php --purge` removes it and leaves the rest alone.
