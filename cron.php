<?php
/**
 * Uptimer : passe de surveillance. À exécuter chaque minute :
 *   * * * * * /usr/bin/php /home/user/uptimer/cron.php >/dev/null 2>&1
 *
 * Ou par URL si crontab n'est pas accessible (clé à définir dans les réglages) :
 *   https://exemple.fr/uptimer/cron.php?key=VOTRECLE
 *
 * Uptimer choisit elle-même les sondes dues : une exécution par minute suffit
 * quels que soient les intervalles configurés.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// Le collecteur écrit des relevés techniques en base : ils le sont dans la
// langue de l'installation (réglage « Langue »), pas dans celle d'un visiteur
// qui n'existe pas ici. Les verdicts, eux, sont stockés en phrase source et
// traduits à l'affichage : voir verdict_text().
Uptimer\I18n::init();

use Uptimer\Config;
use Uptimer\Db;
use Uptimer\Importer;
use Uptimer\Runner;
use Uptimer\Stats;

$isCli = PHP_SAPI === 'cli';
// La passe rend du texte : une erreur fatale doit rester lisible dans le mail
// que le planificateur envoie.
Uptimer\Fail::asText();

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)Config::get('app.cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Accès refusé. Définissez une clé de cron dans les réglages, puis appelez cron.php?key=...\n");
    }
    // La clé de cron est un secret d'exploitation : le détail technique peut sortir.
    Uptimer\Fail::trusted();
    ignore_user_abort(true);
    // La sortie est mise en tampon pour pouvoir répondre 500 sur échec : sinon
    // le premier octet écrit fige le code à 200 et la supervision de la
    // supervision ne voit jamais rien. La passe web est bornée à 25 s, il n'y a
    // donc pas de risque de coupure par un proxy en attendant la fin.
    ob_start();
}

if (!Config::isInstalled()) {
    exit("Uptimer n'est pas installé : ouvrez install.php dans votre navigateur.\n");
}

$t0     = microtime(true);
$budget = (float)($isCli ? ($argv[1] ?? 50) : 25);
$budget = max(5.0, min(280.0, $budget));

// --- Verrou : jamais deux passes en parallèle -----------------------------
$lockFile = UPTIMER_ROOT . '/data/cron.lock';
if (!is_dir(dirname($lockFile))) @mkdir(dirname($lockFile), 0775, true);
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    exit("Impossible d'ouvrir le verrou (droits sur data/ ?).\n");
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Une passe est déjà en cours, on laisse la main.\n";
    exit;
}

$out = function (string $line): void { echo $line . "\n"; };

try {
    Db::migrate();

    // --- 1. Surveillance ------------------------------------------------
    $stats = Runner::runDue(120, $budget * 0.8);
    $out(sprintf('[%s] %d sonde(s) en %.1fs : %d HS, %d dégradée(s), %d OK',
        date('H:i:s'), $stats['ran'], $stats['seconds'], $stats['down'], $stats['degraded'], $stats['up']));

    // Sondes réglées sous la minute : le cron ne peut pas tourner plus souvent,
    // alors la passe se dédouble elle-même dans la minute en cours.
    $subMinute = (int)Db::val('SELECT COUNT(*) FROM monitors WHERE enabled = 1 AND interval_sec < 60', [], 0);
    if ($subMinute > 0) {
        $rounds = 0;
        while ($rounds < 2 && microtime(true) - $t0 < $budget - 12) {
            $wait = 30 - ((int)(microtime(true) - $t0) % 30);
            if ($wait > 0 && $wait <= 30) sleep($wait);
            $extra = Runner::runDue(60, max(5.0, $budget - (microtime(true) - $t0) - 4));
            if ($extra['ran'] > 0) {
                $out(sprintf('  passe intermédiaire : %d sonde(s), %d HS', $extra['ran'], $extra['down']));
            }
            $rounds++;
        }
    }

    // --- 2. Préparation des sondes importées ----------------------------
    if (microtime(true) - $t0 < $budget * 0.9) {
        $pending = Importer::pending(3);
        foreach ($pending as $p) {
            if (microtime(true) - $t0 > $budget * 0.95) break;
            $r = Importer::setup((int)$p['id']);
            $out('  préparation ' . $p['url'] . ' → ' . ($r['message'] ?? ($r['ok'] ? 'ok' : 'échec')));
        }
    }

    // --- 2 bis. Sondes battement : c'est l'absence de signal qui alerte -----
    $hb = Uptimer\Heartbeat::sweep();
    if ($hb) $out('  ' . $hb . ' sonde(s) battement sans signal');

    // --- 3. Agrégats (toutes les 5 minutes) ------------------------------
    if ((int)date('i') % 5 === 0 || (int)Db::setting('stats_never', 1) === 1) {
        $n = Stats::refreshStale(280, 120);
        Db::setSetting('stats_never', '0');
        if ($n) $out('  ' . $n . ' agrégat(s) d\'uptime recalculé(s)');
    }

    // --- 3 bis. Veille de sécurité forcée (php cron.php --vuln) ----------
    if (in_array('--vuln', $argv ?? [], true)) {
        $vs = Uptimer\Vuln::scan(60);
        $out(sprintf('  veille : %d composant(s) vérifié(s), %d avec faille publiée, %d en retard',
            $vs['checked'], $vs['vulnerable'], $vs['outdated']));
    }

    // --- 3 quater. Mesures de terrain forcées (php cron.php --vitals) ----
    if (in_array('--vitals', $argv ?? [], true)) {
        $vt = Uptimer\Vitals::refresh(60);
        $out(Uptimer\Vitals::enabled()
            ? sprintf('  vitesse : %d page(s) interrogée(s), %d mesurée(s), %d en échec',
                      $vt['checked'], $vt['measured'], $vt['poor'])
            : '  vitesse : aucune clé CrUX configurée, mesures de terrain désactivées');
    }

    // --- 3 ter. Rapports mensuels forcés (php cron.php --report) ---------
    if (in_array('--report', $argv ?? [], true)) {
        $rep = Uptimer\Report::runMonthly();
        $out(sprintf('  rapport mensuel : %d envoyé(s), %d en échec, %d ignoré(s)',
            $rep['sent'], $rep['failed'], $rep['skipped']));
        foreach ($rep['detail'] as $d) $out('    ' . $d['site'] . ' : ' . $d['info']);
    }

    // --- 4. Entretien quotidien (vers 3 h du matin) ----------------------
    $today = date('Y-m-d');
    if ((int)date('G') === 3 && Db::setting('daily_done') !== $today) {
        Db::setSetting('daily_done', $today);
        $tuned = Uptimer\Tune::run(40);
        if ($tuned) $out('  ' . $tuned . ' seuil(s) de lenteur réajusté(s)');
        $roll = Stats::rollup(date('Y-m-d', time() - 86400));
        $pur  = Stats::purge();
        // Répare ce qu'une version antérieure a pu laisser : mesures sans sonde,
        // inventaire d'un site vidé, site sans aucune sonde. Sans cela la veille
        // interrogerait chaque jour des composants de sites disparus.
        $rep  = Db::repairOrphans();
        if ($rep['orphans'] || $rep['sites'] || $rep['components']) {
            $out(sprintf('  réparation : %d ligne(s) orpheline(s), %d site(s) vide(s), %d composant(s)',
                $rep['orphans'], $rep['sites'], $rep['components']));
        }
        $dom  = Runner::refreshDomains(25);
        Stats::refreshStale(0, 500);
        $out(sprintf('  entretien : %d jour(s) consolidé(s), %d mesure(s) purgée(s), %d domaine(s) vérifié(s)',
            $roll, $pur, $dom));

        // Veille de sécurité : une interrogation par composant et par version,
        // mise en cache sept jours, plafonnée pour ne pas charger le mutualisé.
        $vs = Uptimer\Vuln::scan();
        if ($vs['checked']) {
            $out(sprintf('  veille : %d composant(s) vérifié(s), %d avec faille publiée, %d en retard',
                $vs['checked'], $vs['vulnerable'], $vs['outdated']));
        }

        // Mesures de terrain : une interrogation par page et par jour, et
        // seulement si une clé CrUX est configurée.
        $vt = Uptimer\Vitals::refresh();
        if ($vt['checked']) {
            $out(sprintf('  vitesse : %d page(s) interrogée(s), %d mesurée(s), %d en échec',
                $vt['checked'], $vt['measured'], $vt['poor']));
        }

        // Rapports mensuels : l'envoi est marqué par une clé de mois, donc le
        // repasser ici chaque jour ne peut pas produire de doublon.
        $rep = Uptimer\Report::runMonthly();
        if ($rep['sent'] || $rep['failed']) {
            $out(sprintf('  rapport mensuel : %d envoyé(s), %d en échec', $rep['sent'], $rep['failed']));
            foreach ($rep['detail'] as $d) {
                if (!$d['ok']) $out('    ' . $d['site'] . ' : ' . $d['info']);
            }
        }
    }

    // --- 5. Consolidation du jour en cours (pour la frise 30 jours) ------
    if ((int)date('i') % 30 === 0) {
        Stats::rollup($today);
    }

    Db::setSetting('cron_last_ok', now());
} catch (Throwable $e) {
    $out('ERREUR : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    try { Db::setSetting('cron_last_error', date('Y-m-d H:i:s') . ' : ' . $e->getMessage()); } catch (Throwable) {}
    $failed = true;
    if ($isCli) { flock($lock, LOCK_UN); fclose($lock); exit(1); }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$out(sprintf('Terminé en %.1fs', microtime(true) - $t0));

// Un appel web qui a échoué doit le dire par son code de réponse : c'est ce que
// lit un « curl -fsS » de surveillance, et ce qui déclenche le mail du
// planificateur. Une passe partielle reste un succès.
if (!$isCli) {
    if (!empty($failed)) http_response_code(500);
    ob_end_flush();
}
