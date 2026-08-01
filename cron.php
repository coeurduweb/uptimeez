<?php
/**
 * UptimeEZ : passe de surveillance. À exécuter chaque minute :
 *   * * * * * /usr/bin/php /home/user/uptimeez/cron.php >/dev/null 2>&1
 *
 * Ou par URL si crontab n'est pas accessible (clé à définir dans les réglages) :
 *   https://exemple.fr/uptimeez/cron.php?key=VOTRECLE
 *
 * UptimeEZ choisit elle-même les sondes dues : une exécution par minute suffit
 * quels que soient les intervalles configurés.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// Le collecteur écrit des relevés techniques en base : ils le sont dans la
// langue de l'installation (réglage « Langue »), pas dans celle d'un visiteur
// qui n'existe pas ici. Les verdicts, eux, sont stockés en phrase source et
// traduits à l'affichage : voir verdict_text().
Uptimeez\I18n::init();

use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Importer;
use Uptimeez\Runner;
use Uptimeez\Stats;

$isCli = PHP_SAPI === 'cli';
// La passe rend du texte : une erreur fatale doit rester lisible dans le mail
// que le planificateur envoie.
Uptimeez\Fail::asText();

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)Config::get('app.cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Accès refusé. Définissez une clé de cron dans les réglages, puis appelez cron.php?key=...\n");
    }
    // La clé de cron est un secret d'exploitation : le détail technique peut sortir.
    Uptimeez\Fail::trusted();
    ignore_user_abort(true);
    // La sortie est mise en tampon pour pouvoir répondre 500 sur échec : sinon
    // le premier octet écrit fige le code à 200 et la supervision de la
    // supervision ne voit jamais rien. La passe web est bornée à 25 s, il n'y a
    // donc pas de risque de coupure par un proxy en attendant la fin.
    ob_start();
}

if (!Config::isInstalled()) {
    exit("UptimeEZ n'est pas installé : ouvrez install.php dans votre navigateur.\n");
}

$t0   = microtime(true);
$args = $isCli ? array_slice($argv, 1) : [];
$flag = fn(string $f): bool => in_array($f, $args, true);

// Le budget se lit sur le PREMIER argument numérique, pas sur le premier
// argument. « php cron.php --maint » passait auparavant « --maint » à (float),
// donc 0, donc un budget ramené au plancher de 5 secondes : la passe s'arrêtait
// au milieu sans que rien ne le dise.
$budget = 25.0;
if ($isCli) {
    $budget = 50.0;
    foreach ($args as $a) {
        if (is_numeric($a)) { $budget = (float)$a; break; }
    }
}
$budget = max(5.0, min(280.0, $budget));

// Drapeaux d'exploitation. La documentation les annonçait depuis le début ;
// aucun n'existait, et un drapeau inconnu était silencieusement ignoré.
$only     = $flag('--setup') || $flag('--maint') || $flag('--vacuum');
$doWatch  = !$only;
$doSetup  = !$only || $flag('--setup');
$doMaint  = $flag('--maint');
// « --once » : une seule passe de surveillance, sans les passes intermédiaires
// des sondes réglées sous la minute. C'est ce qu'on lance pour regarder.
$once     = $flag('--once');
foreach ($args as $a) {
    if (!is_numeric($a) && !in_array($a, ['--once', '--setup', '--maint', '--vacuum',
                                          '--vuln', '--vitals', '--report'], true)) {
        echo "Option inconnue : $a\n";
        echo "Connues : --once --setup --maint --vacuum --vuln --vitals --report [budget en secondes]\n";
        exit(2);
    }
}

// --- Verrou : jamais deux passes en parallèle SUR LA MÊME INSTANCE --------
//
// Le verrou vit à côté de la configuration de l'instance, pas à côté du code.
// Il était pris sur UPTIMEEZ_ROOT/data/cron.lock, c'est-à-dire dans le dossier du
// moteur, qui est PARTAGÉ dès que plusieurs instances tournent sur un seul
// exemplaire du code : à dix clients, neuf passes recevaient « une passe est déjà
// en cours » chaque minute et repartaient sans rien vérifier, en silence. Voir
// Config::dataDir() pour le raisonnement complet.
$lockFile = Config::dataDir() . '/cron.lock';
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
    // « --setup », « --maint » et « --vacuum » ne surveillent rien : ce sont des
    // gestes d'entretien, et les lancer ne doit pas décaler la cadence des
    // sondes en marquant leur prochain passage.
    if ($doWatch) {
        $stats = Runner::runDue(120, $budget * 0.8);
        $out(sprintf('[%s] %d sonde(s) en %.1fs : %d HS, %d dégradée(s), %d OK',
            date('H:i:s'), $stats['ran'], $stats['seconds'], $stats['down'], $stats['degraded'], $stats['up']));

        // Sondes réglées sous la minute : le cron ne peut pas tourner plus
        // souvent, alors la passe se dédouble elle-même dans la minute en cours.
        // « --once » s'en dispense : on veut une passe qu'on regarde, pas une
        // passe qui dort trente secondes.
        $subMinute = $once ? 0
            : (int)Db::val('SELECT COUNT(*) FROM monitors WHERE enabled = 1 AND interval_sec < 60', [], 0);
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
    }

    // --- 2. Préparation des sondes importées ----------------------------
    if ($doSetup && microtime(true) - $t0 < $budget * 0.9) {
        $pending = Importer::pending(3);
        foreach ($pending as $p) {
            if (microtime(true) - $t0 > $budget * 0.95) break;
            $r = Importer::setup((int)$p['id']);
            $out('  préparation ' . $p['url'] . ' → ' . ($r['message'] ?? ($r['ok'] ? 'ok' : 'échec')));
        }
    }

    // --- 2 bis. Sondes battement : c'est l'absence de signal qui alerte -----
    if ($doWatch) {
        $hb = Uptimeez\Heartbeat::sweep();
        if ($hb) $out('  ' . $hb . ' sonde(s) battement sans signal');
    }

    // --- 3. Agrégats (toutes les 5 minutes) ------------------------------
    if ($doWatch && ((int)date('i') % 5 === 0 || (int)Db::setting('stats_never', 1) === 1)) {
        $n = Stats::refreshStale(280, 120);
        Db::setSetting('stats_never', '0');
        if ($n) $out('  ' . $n . ' agrégat(s) d\'uptime recalculé(s)');
    }

    // --- 3 bis. Veille de sécurité forcée (php cron.php --vuln) ----------
    if ($flag('--vuln')) {
        $vs = Uptimeez\Vuln::scan(60);
        $out(sprintf('  veille : %d composant(s) vérifié(s), %d avec faille publiée, %d en retard',
            $vs['checked'], $vs['vulnerable'], $vs['outdated']));
    }

    // --- 3 quater. Mesures de terrain forcées (php cron.php --vitals) ----
    if ($flag('--vitals')) {
        $vt = Uptimeez\Vitals::refresh(60);
        $out(Uptimeez\Vitals::enabled()
            ? sprintf('  vitesse : %d page(s) interrogée(s), %d mesurée(s), %d en échec',
                      $vt['checked'], $vt['measured'], $vt['poor'])
            : '  vitesse : aucune clé CrUX configurée, mesures de terrain désactivées');
    }

    // --- 3 ter. Rapports mensuels forcés (php cron.php --report) ---------
    if ($flag('--report')) {
        $rep = Uptimeez\Report::runMonthly();
        $out(sprintf('  rapport mensuel : %d envoyé(s), %d en échec, %d ignoré(s)',
            $rep['sent'], $rep['failed'], $rep['skipped']));
        foreach ($rep['detail'] as $d) $out('    ' . $d['site'] . ' : ' . $d['info']);
    }

    // --- 3 quinquies. Purge restée en travers ----------------------------
    // Ramener la conservation de 60 à 7 jours supprime des millions de lignes.
    // La purge travaille par tranches et note ce qui reste : chaque passe en
    // reprend un morceau, plutôt qu'une seule tenant le verrou d'écriture une
    // minute entière — pendant laquelle toute l'interface échouait sur
    // « database is locked ».
    if (!$only && Stats::purgePending() && microtime(true) - $t0 < $budget * 0.9) {
        $n = Stats::purge(null, min(6.0, $budget * 0.2));
        if ($n) $out(sprintf('  purge en cours : %d mesure(s) retirées%s',
            $n, Stats::purgePending() ? ', reprise à la passe suivante' : ', terminé'));
    }

    // --- 4. Entretien quotidien (vers 3 h du matin) ----------------------
    $today = date('Y-m-d');
    $last  = (string)Db::setting('daily_done', '');
    // L'heure de 3 h est une préférence, pas une condition. Une machine éteinte
    // la nuit, un conteneur arrêté, une tâche planifiée coupée quelques jours :
    // l'entretien ne tournait alors JAMAIS, et la table des mesures grossissait
    // sans limite. Passé deux jours sans entretien, la passe suivante prend la
    // main quelle que soit l'heure.
    $late = $last === '' || $last < date('Y-m-d', time() - 2 * 86400);
    // « --maint » force l'entretien maintenant, sans attendre 3 h du matin ni la
    // remise à zéro du témoin : c'est ce qu'on lance après avoir changé la durée
    // de conservation.
    if ($doMaint || ($last !== $today && ((int)date('G') === 3 || $late))) {
        Db::setSetting('daily_done', $today);
        $tuned = Uptimeez\Tune::run(40);
        if ($tuned) $out('  ' . $tuned . ' seuil(s) de lenteur réajusté(s)');
        $roll = Stats::rollup(date('Y-m-d', time() - 86400));
        // Puis les jours qu'un entretien manqué avait laissés sans résumé : sans
        // ça, la frise 30 jours gardait un trou définitif après une coupure.
        $back = Stats::rollupMissing(5);
        if ($back) $out('  ' . $back . ' jour(s) de retard consolidé(s)');
        $pur  = Stats::purge(null, 8.0);
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
            $roll + $back, $pur, $dom));
        if (Stats::purgePending()) $out('  purge non terminée : elle reprendra à la passe suivante');
        // L'espace rendu se dit, et le VACUUM qui reste à faire aussi : sur une
        // base créée avant la correction de l'ordre des PRAGMA, les pages libres
        // sont inatteignables sans lui.
        $cmp = Db::compact(6.0);
        if ($cmp['freed_bytes'] > 0) $out('  ' . human_bytes($cmp['freed_bytes']) . ' rendus au disque');
        if ($cmp['needs_vacuum']) {
            $out(sprintf('  %d pages libres non rendues : lancez « php cron.php --vacuum » '
                       . 'une fois, hors heures de pointe', $cmp['free_pages']));
        }

        // Veille de sécurité : une interrogation par composant et par version,
        // mise en cache sept jours, plafonnée pour ne pas charger le mutualisé.
        $vs = Uptimeez\Vuln::scan();
        if ($vs['checked']) {
            $out(sprintf('  veille : %d composant(s) vérifié(s), %d avec faille publiée, %d en retard',
                $vs['checked'], $vs['vulnerable'], $vs['outdated']));
        }

        // Mesures de terrain : une interrogation par page et par jour, et
        // seulement si une clé CrUX est configurée.
        $vt = Uptimeez\Vitals::refresh();
        if ($vt['checked']) {
            $out(sprintf('  vitesse : %d page(s) interrogée(s), %d mesurée(s), %d en échec',
                $vt['checked'], $vt['measured'], $vt['poor']));
        }

        // Rapports mensuels : l'envoi est marqué par une clé de mois, donc le
        // repasser ici chaque jour ne peut pas produire de doublon.
        $rep = Uptimeez\Report::runMonthly();
        if ($rep['sent'] || $rep['failed']) {
            $out(sprintf('  rapport mensuel : %d envoyé(s), %d en échec', $rep['sent'], $rep['failed']));
            foreach ($rep['detail'] as $d) {
                if (!$d['ok']) $out('    ' . $d['site'] . ' : ' . $d['info']);
            }
        }
    }

    // --- 4 bis. VACUUM à la demande --------------------------------------
    // Réservé à un appel explicite : il réécrit tout le fichier et verrouille
    // pendant ce temps. C'est le geste d'un exploitant, pas d'une passe de cron.
    if ($flag('--vacuum')) {
        $v = Db::vacuum();
        $out($v['ok']
            ? sprintf('  VACUUM : %s rendus en %.1f s', human_bytes($v['freed_bytes']), $v['seconds'])
            : '  VACUUM impossible : ' . $v['error']);
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
