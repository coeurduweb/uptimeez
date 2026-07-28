# Installation

[← Documentation](README.md) · [Getting started →](getting-started.md)

Uptimer is a plain PHP application. There is no build step, no package manager and no container. If you can
upload files by FTP and add a cron entry, you can run it.

---

## Requirements

| | |
|---|---|
| **PHP** | 8.1 or newer, CLI *and* web |
| **Extensions** | `curl`, `json`, `mbstring`, and `pdo_sqlite` (default) or `pdo_mysql` |
| **Write access** | the `data/` folder, and the root folder once (to create `config.php`) |
| **Cron** | one entry per minute — or any external scheduler able to call a URL |
| **Outgoing HTTPS** | the collector needs to reach the sites you monitor |

Nothing else. The installer checks all of it and tells you what is missing before writing anything.

> **A note on `intl`.** If the extension is present, accent-insensitive search uses Unicode normalisation for
> every language. Without it, a built-in fallback table covers extended Latin. Nothing breaks either way.

---

## Standard installation

```bash
git clone https://github.com/loran750/uptimer.git
cd uptimer
chmod -R 775 data
```

Then open `install.php` in a browser:

1. It verifies the environment and shows a green/red checklist.
2. You choose a password (8 characters minimum). It is stored hashed with `password_hash()`.
3. It writes `config.php` and creates the database.
4. It refuses to run a second time — reinstalling means deleting `config.php` by FTP or SSH first.

Finally, add the cron entry (see below) and open the settings screen to configure one alert channel.

---

## Shared hosting (o2switch, cPanel, Plesk, OVH…)

This is the primary target, not an afterthought.

1. Upload the folder into `public_html/uptimer/` (or wherever you serve from).
2. Set `data/` to `775` in the file manager.
3. Visit `https://yourdomain.com/uptimer/install.php`.
4. In cPanel → **Cron jobs**, frequency **every minute**:

   ```
   * * * * * /usr/local/bin/php /home/YOURACCOUNT/public_html/uptimer/cron.php >/dev/null 2>&1
   ```

   The exact line, with the right PHP path for your account, is displayed in **Settings → Scheduled task** —
   copy it from there rather than guessing.

**o2switch specifics.** The PHP binary is usually `/usr/local/bin/php`. LiteSpeed ignores some `.htaccess`
rewrite flags, which is why Uptimer never relies on URL rewriting: every URL is a plain
`index.php?p=…`. Nothing to configure.

**No crontab at all?** Settings → *Trigger over URL* gives you a secret URL:

```
https://yourdomain.com/uptimer/cron.php?key=YOUR_KEY
```

Call it every minute from any external scheduler (cron-job.org, EasyCron, a GitHub Action, another server's
crontab). Without the correct key the endpoint answers 403 and does nothing.

---

## Protecting the installation

Uptimer is password-protected and sends `noindex, nofollow` on every page. For a belt-and-braces setup:

- Put it on a subdomain you do not advertise, or in a folder with a non-obvious name.
- Add HTTP authentication on top (cPanel → *Directory Privacy*) if you like.
- Keep `config.php` out of version control — it holds your password hash and your webhook URLs.
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
    'name'    => 'uptimer',
    'user'    => 'uptimer',
    'pass'    => '…',
    'charset' => 'utf8mb4',
],
```

The schema is created and upgraded automatically on the next page load. There is no migration to run and no
destructive change: new columns are added, existing ones are never dropped.

To move existing history across, export the SQLite tables and import them — the schema is identical apart from
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
