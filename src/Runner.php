<?php
declare(strict_types=1);

namespace Uptimeez;

use Uptimeez\Check\Css;
use Uptimeez\Check\Database;
use Uptimeez\Check\DomainExpiry;
use Uptimeez\Check\Silhouette;
use Uptimeez\Check\Ssl;
use Uptimeez\Check\Vitals;
use Uptimeez\Detect\Discovery;
use Uptimeez\Notify\Notifier;
use Uptimeez\Vuln;

/**
 * Orchestration d'une passe de surveillance.
 *
 * Principe : une passe = récupération parallèle des pages dues, puis évaluation
 * de chaque réponse par les sondes, puis moteur d'incidents et notifications.
 * Les relances (anti faux positif) se font sur un second tour, pas en série.
 */
final class Runner
{
    public const SEVERITY = ['up' => 0, 'degraded' => 1, 'down' => 2];

    /**
     * Délai avant de confirmer un premier échec, en secondes.
     *
     * Trente secondes couvrent ce qui produit les fausses alertes : redémarrage de
     * PHP-FPM, purge de cache, pic de charge. Plus court ne laisserait pas le temps au
     * serveur de revenir ; plus long retarderait une vraie panne sans rien gagner, la
     * quasi-totalité de ces incidents se résolvant sous dix secondes.
     */
    public const CONFIRMATION_SEC = 30;

    /**
     * Une analyse CSS télécharge toutes les feuilles de la page : c'est la sonde
     * la plus coûteuse. On en limite le nombre par passe pour qu'une minute de
     * cron reste une minute de cron, même avec 300 sites. Les sondes non
     * analysées gardent leur dernier verdict et passent au tour suivant.
     */
    public const CSS_AUDITS_PER_PASS = 6;
    private static int $cssAudits = 0;

    /**
     * Pannes détectées pendant la passe en cours, en attente de corrélation.
     * @var array<int,array{monitor:array,incident:array}>
     */
    private static array $pendingAlerts = [];

    /** Au-delà de ce nombre de sites touchés sur un même serveur, on regroupe. */
    public const GROUP_THRESHOLD = 3;

    public static function resetPassCounters(): void
    {
        self::$cssAudits = 0;
        self::$pendingAlerts = [];
    }

    /**
     * Envoie les alertes de la passe en les regroupant par cause commune.
     *
     * Quand un serveur mutualisé tombe, ce ne sont pas quarante sites qui ont un
     * problème : c'est une machine. On envoie donc un message unique qui nomme le
     * serveur et liste les sites touchés, au lieu de quarante alertes illisibles.
     */
    public static function flushAlerts(): int
    {
        $pending = self::$pendingAlerts;
        self::$pendingAlerts = [];
        if (!$pending) return 0;

        // Regroupement par adresse IP contactée, puis par domaine enregistrable :
        // deux signatures fiables d'une infrastructure commune.
        $groups = [];
        foreach ($pending as $item) {
            $mon = $item['monitor'];
            $ip  = (string)($mon['last_ip'] ?? '');
            $key = $ip !== '' ? 'ip:' . $ip : 'dom:' . registrable_domain(host_of((string)$mon['url']));
            $groups[$key][] = $item;
        }

        $sent = 0;
        foreach ($groups as $key => $items) {
            // Un même site ne compte qu'une fois : trois pages d'un seul site en
            // panne, c'est un site en panne, pas trois.
            $sites = [];
            foreach ($items as $it) {
                $sid = $it['monitor']['site_id'] ?: ('m' . $it['monitor']['id']);
                $sites[$sid] = true;
            }
            if (count($items) >= self::GROUP_THRESHOLD && count($sites) >= self::GROUP_THRESHOLD) {
                $scope = str_starts_with($key, 'ip:') ? substr($key, 3) : substr($key, 4);
                Notifier::sendGrouped($items, $scope, str_starts_with($key, 'ip:'));
                $sent++;
                continue;
            }
            foreach ($items as $it) {
                Notifier::sendIncident($it['monitor'], $it['incident'], 'nouveau');
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * La « grappe » à laquelle appartient une sonde : ce qui, pour la cible, ressemble à
     * un seul serveur.
     *
     * POURQUOI L'ADRESSE IP ET NON LE NOM DE DOMAINE
     *
     * Un hébergement mutualisé sert des dizaines de domaines depuis une seule machine et
     * une seule adresse. Grouper par nom de domaine ne verrait donc rien : quarante sites
     * d'un même cPanel formeraient quarante grappes d'un élément, et l'étalement ne
     * s'appliquerait à rien. C'est précisément le cas du parc réel, où un client a
     * plusieurs dizaines de sites sur le même compte d'hébergement.
     *
     * Repli sur le nom quand l'adresse n'est pas encore connue, c'est-à-dire au tout
     * premier passage d'une sonde neuve. Un repli valait mieux qu'un groupe « inconnu »
     * commun, qui aurait réuni toutes les sondes neuves dans une même grappe et les aurait
     * étalées les unes par rapport aux autres alors qu'elles ne se gênent pas.
     */
    public static function grappeServeur(array $mon): string
    {
        $ip = trim((string)($mon['last_ip'] ?? ''));

        return $ip !== '' ? 'ip:' . $ip : 'hote:' . strtolower(host_of((string)($mon['url'] ?? '')));
    }

    /**
     * Quand cette sonde repassera, de façon à ce que les sondes d'une MÊME grappe se
     * répartissent sur toute la durée de l'intervalle.
     *
     * LE PROBLÈME QUE ÇA RÈGLE, ET QU'UN ALÉA NE RÉGLAIT PAS
     *
     * La version précédente ajoutait « random_int(0, interval/8) », plafonné à 45
     * secondes. Deux défauts, et le second est le vrai.
     *
     * Un aléa borné à 45 secondes ne disperse rien sur un intervalle de quinze minutes :
     * quarante sites d'un même hébergeur mutualisé partaient dans une fenêtre de moins
     * d'une minute, ce qui est exactement le profil qu'un pare-feu applicatif appelle une
     * attaque. Et surtout, un aléa est SANS MÉMOIRE : deux sondes peuvent tirer la même
     * valeur, à chaque passage, indéfiniment. Il réduit la probabilité d'une collision, il
     * ne garantit aucun espacement.
     *
     * Ici, chaque sonde reçoit un RANG stable dans sa grappe et occupe le créneau
     * correspondant. Trente sites sur un même serveur avec un intervalle de 900 secondes
     * sont interrogés toutes les 30 secondes, l'un après l'autre, indéfiniment.
     *
     * POURQUOI ON S'ACCROCHE À UNE GRILLE ET NON À « MAINTENANT »
     *
     * « maintenant + intervalle + créneau » dérive : chaque passage ajoute le temps de la
     * requête, si bien que les créneaux se rapprochent puis se recouvrent au bout de
     * quelques heures. On calcule donc le début de la prochaine fenêtre absolue, et on y
     * ajoute le créneau. Le rang d'une sonde vaut la même seconde de chaque fenêtre, quel
     * que soit le temps qu'a pris la requête précédente.
     */
    public static function prochainPassage(array $mon, ?int $maintenant = null): int
    {
        $maintenant = $maintenant ?? time();
        $intervalle = max(60, (int)($mon['interval_sec'] ?? 300));
        $grappe = self::grappeServeur($mon);
        [$rang, $taille] = self::rangDansLaGrappe($mon);

        // LA PHASE DE LA GRAPPE, SANS LAQUELLE TOUTES LES GRAPPES DÉMARRENT ENSEMBLE.
        //
        // Le rang seul donne le créneau 0 au premier élément de CHAQUE grappe. Mesuré sur
        // le parc réel du 2026-08-01 : 200 sondes réparties sur 16 minutes, mais une
        // minute en portait 30 contre 12,5 en moyenne, parce que les vingt grappes
        // partaient toutes à la même seconde. Aucun serveur n'en souffrait, chacun ne
        // recevant qu'une requête, mais notre propre charge était inégale et le pic
        // tombait pile au changement de fenêtre.
        //
        // La phase est tirée du NOM de la grappe, donc stable et sans mémoire à conserver.
        // crc32 suffit : on ne cherche pas une propriété cryptographique, seulement une
        // répartition qui ne dépende pas de l'ordre de création.
        //
        // Elle remplace aussi le dernier « random_int » du calcul, celui des grappes d'un
        // seul élément. Toute la planification est désormais déterministe, donc
        // reproductible dans un test : c'est ce qui permet au selftest d'exiger un
        // espacement plutôt que de constater une dispersion.
        $phase = crc32($grappe) % $intervalle;
        $creneau = $taille > 1
            ? (int)(($phase + (int)round($rang * $intervalle / $taille)) % $intervalle)
            : $phase;

        // LA FENÊTRE COURANTE D'ABORD, LA SUIVANTE SEULEMENT SI LE CRÉNEAU EST PASSÉ.
        //
        // La première version visait toujours « fenêtre suivante + créneau ». En régime
        // établi c'est correct, puisque la sonde vient de passer à son créneau et que le
        // prochain est exactement un intervalle plus loin. Mais tout ce qui replanifie
        // HORS de ce rythme ouvrait un trou : mesuré le 2026-08-01 après un changement
        // d'intervalle sur les 200 sondes d'un client, plus une seule vérification pendant
        // onze minutes. Le même trou s'ouvrait à chaque modification d'un réglage depuis
        // l'écran, à la reprise d'une sonde en pause, et à la première passe après une
        // panne du planificateur, c'est-à-dire précisément au moment où l'on veut savoir.
        //
        // Le trou était d'autant plus traître qu'il ne ressemble pas à une panne : les
        // écrans affichent les derniers relevés, tout paraît normal, il ne se passe
        // simplement rien.
        $debutFenetre = intdiv($maintenant, $intervalle) * $intervalle;
        $passage = $debutFenetre + $creneau;

        // Créneau déjà passé dans cette fenêtre : on prend celui de la suivante. La boucle
        // couvre aussi le cas d'une sonde très en retard, où plusieurs fenêtres ont pu
        // s'écouler ; replanifier dans le passé la ferait repartir en boucle.
        while ($passage <= $maintenant) $passage += $intervalle;

        return $passage;
    }

    /**
     * Le rang de cette sonde dans sa grappe, et la taille de la grappe.
     *
     * Le rang est le NOMBRE DE SONDES DE LA GRAPPE DONT L'IDENTIFIANT EST PLUS PETIT.
     * C'est stable tant que la grappe ne change pas, ça ne demande aucune colonne
     * supplémentaire, et l'ordre est le même pour toutes les sondes de la grappe, donc les
     * créneaux ne se recouvrent pas.
     *
     * @return array{0:int,1:int}
     */
    private static function rangDansLaGrappe(array $mon): array
    {
        $grappe = self::grappeServeur($mon);
        $id     = (int)($mon['id'] ?? 0);

        // La grappe se recalcule en SQL sur la même règle que grappeServeur() : adresse
        // connue, sinon nom. Écrire la règle deux fois est un risque de divergence, et
        // c'est assumé ici : la faire en PHP demanderait de charger toutes les sondes à
        // chaque planification. Le contrôle de bin/selftest.php compare les deux.
        $prefixe = substr($grappe, 0, 3) === 'ip:' ? substr($grappe, 3) : null;

        if ($prefixe !== null) {
            $ligne = Db::one(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN id < ? THEN 1 ELSE 0 END) AS avant
                   FROM monitors
                  WHERE enabled = 1 AND kind <> 'heartbeat' AND last_ip = ?",
                [$id, $prefixe]
            );
        } else {
            $ligne = Db::one(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN id < ? THEN 1 ELSE 0 END) AS avant
                   FROM monitors
                  WHERE enabled = 1 AND kind <> 'heartbeat'
                    AND (last_ip IS NULL OR last_ip = '')
                    AND lower(url) LIKE ?",
                [$id, '%' . strtolower(host_of((string)($mon['url'] ?? ''))) . '%']
            );
        }

        $total = max(1, (int)($ligne['total'] ?? 1));
        $avant = (int)($ligne['avant'] ?? 0);

        return [$avant, $total];
    }

    /**
     * Réordonne les sondes dues pour qu'un même serveur n'apparaisse jamais deux fois de
     * suite, donc jamais deux fois dans le même paquet parallèle.
     *
     * POURQUOI L'ÉTALEMENT DANS LE TEMPS NE SUFFIT PAS
     *
     * prochainPassage() empêche deux sondes d'une même grappe d'être dues à la même
     * seconde, mais pas d'être dues dans la même PASSE : une sonde en retard, une reprise
     * après une panne du planificateur, ou simplement des sondes neuves dont le
     * next_check_at est NULL, et la passe ramasse tout le lot d'un coup. Les sondes neuves
     * sont le cas le plus courant : à la création d'un compte, les deux cents partent
     * ensemble, c'est-à-dire au pire moment, celui où le client regarde.
     *
     * L'entrelacement en tourniquet garantit que, tant qu'il y a au moins autant de
     * grappes que la taille d'un paquet, aucun paquet ne contient deux sondes du même
     * serveur.
     *
     * @param  list<array<string,mixed>>  $mons
     * @return list<array<string,mixed>>
     */
    public static function entrelacerParServeur(array $mons): array
    {
        if (count($mons) < 2) return $mons;

        $files = [];
        foreach ($mons as $mon) $files[self::grappeServeur($mon)][] = $mon;

        // Les grosses grappes en premier : sinon une grappe de trente face à dix grappes
        // d'une se retrouve concentrée en fin de liste, donc de nouveau groupée.
        uasort($files, static fn (array $a, array $b): int => count($b) <=> count($a));

        $sortie = [];
        while ($files) {
            foreach ($files as $cle => $file) {
                $sortie[] = array_shift($files[$cle]);
                if (!$files[$cle]) unset($files[$cle]);
            }
        }

        return $sortie;
    }

    /** Sondes dues à cet instant. */
    public static function due(int $limit = 60): array
    {
        return Db::all(
            "SELECT * FROM monitors
             WHERE enabled = 1 AND kind <> 'heartbeat'
               AND (next_check_at IS NULL OR next_check_at <= ?)
             ORDER BY (next_check_at IS NULL) DESC, next_check_at ASC
             LIMIT " . max(1, $limit),
            [now()]
        );
    }

    /**
     * Exécute une passe complète.
     * @return array{ran:int,down:int,degraded:int,up:int,seconds:float}
     */
    public static function runDue(int $limit = 60, float $budgetSec = 50.0): array
    {
        $t0    = microtime(true);
        self::resetPassCounters();
        $stats = ['ran' => 0, 'down' => 0, 'degraded' => 0, 'up' => 0, 'seconds' => 0.0];
        $mons  = self::due($limit);
        if (!$mons) { $stats['seconds'] = round(microtime(true) - $t0, 2); return $stats; }

        // L'ordre décide de ce qui part ENSEMBLE, puisque la découpe en paquets suit la
        // liste. Sans cet entrelacement, une passe qui ramasse trente sondes d'un même
        // hébergeur mutualisé lui envoie dix requêtes simultanées, trois fois de suite.
        $mons = self::entrelacerParServeur($mons);

        $parallel = (int)Config::get('defaults.max_parallel', 10);

        foreach (array_chunk($mons, max(1, $parallel)) as $batch) {
            if (microtime(true) - $t0 > $budgetSec) break;
            $results = self::runBatch($batch);
            foreach ($results as $r) {
                $stats['ran']++;
                $key = $r['state'] === 'paused' ? 'up' : $r['state'];
                if (isset($stats[$key])) $stats[$key]++;
            }
        }
        self::flushAlerts();
        $stats['seconds'] = round(microtime(true) - $t0, 2);
        Db::setSetting('last_run_at', now());
        Db::setSetting('last_run_stats', jenc($stats));
        return $stats;
    }

    /** Lance une sonde à la demande (bouton « vérifier maintenant »). */
    public static function runOne(int $monitorId): ?array
    {
        $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [$monitorId]);
        if (!$mon) return null;
        $r = self::runBatch([$mon], true);
        self::flushAlerts();   // vérification unitaire : rien à corréler
        return $r[0] ?? null;
    }

    /**
     * Traite un lot de sondes : fetch parallèle, relances, évaluation, persistance.
     * @return array<int,array>
     */
    public static function runBatch(array $monitors, bool $manual = false): array
    {
        $out      = [];
        $requests = [];
        $byKey    = [];

        foreach ($monitors as $mon) {
            $id = (int)$mon['id'];
            // Une sonde battement ne s'interroge pas : c'est elle qui nous appelle.
            if (($mon['kind'] ?? '') === 'heartbeat') continue;
            if (!$manual && self::isPaused($mon)) {
                self::persistPaused($mon);
                $out[] = ['monitor_id' => $id, 'state' => 'paused', 'reason' => null, 'message' => 'En pause'];
                continue;
            }
            $requests['m' . $id] = [$mon['url'], self::requestOptions($mon)];
            $byKey['m' . $id]    = $mon;
        }
        if (!$requests) return $out;

        $responses = Http::fetchMany($requests, (int)Config::get('defaults.max_parallel', 10));

        // --- Relances ciblées sur les échecs réseau / 5xx --------------------
        $attempts = array_fill_keys(array_keys($requests), 1);
        for ($round = 0; $round < 3; $round++) {
            $retry = [];
            foreach ($byKey as $key => $mon) {
                $res = $responses[$key] ?? null;
                $max = (int)$mon['retries'] + 1;
                if ($attempts[$key] >= $max) continue;
                if ($res === null || self::worthRetrying($res)) {
                    $retry[$key] = [$mon['url'], self::requestOptions($mon)];
                }
            }
            if (!$retry) break;
            usleep((int)Config::get('defaults.retry_delay_ms', 1500) * 1000);
            foreach (Http::fetchMany($retry, (int)Config::get('defaults.max_parallel', 10)) as $key => $res) {
                $attempts[$key]++;
                // On garde la meilleure des tentatives : un succès annule l'échec.
                $prev = $responses[$key] ?? null;
                if ($prev === null || self::worthRetrying($prev)) $responses[$key] = $res;
            }
        }

        // --- Évaluation ----------------------------------------------------
        foreach ($byKey as $key => $mon) {
            $res     = $responses[$key] ?? new Response();
            $verdict = self::evaluate($mon, $res, $manual);
            self::persist($mon, $res, $verdict, $attempts[$key] ?? 1);
            $out[] = $verdict + ['monitor_id' => (int)$mon['id']];
        }
        return $out;
    }

    // =====================================================================
    // Évaluation
    // =====================================================================

    /**
     * Applique toutes les sondes à une réponse et retient le verdict le plus grave.
     * @return array{state:string,reason:?string,message:string,details:array,events:array}
     */
    public static function evaluate(array $mon, Response $res, bool $manual = false): array
    {
        $findings = [];   // [state, reason, message]
        $details  = [];
        $events   = [];
        $https    = str_starts_with(strtolower((string)$mon['url']), 'https://');
        // La réponse a-t-elle été reçue en entier ? Au-delà de Http::MAX_BODY le
        // corps est coupé, et une absence de texte n'y prouve plus rien. C'est le
        // cas qui transformait une page de catalogue trop lourde en « chaîne de
        // contrôle absente », donc en « la base de données ne répond plus », donc
        // en fausse panne. Le drapeau existait et personne ne le lisait.
        $complete = !$res->truncated;

        // Le message est une phrase source avec ses variables : la traduction a
        // lieu à l'affichage, pas ici. Le collecteur ne connaît pas la langue de
        // celui qui lira le verdict.
        $note = function (string $state, ?string $reason, string $message, array $vars = []) use (&$findings) {
            $findings[] = ['state' => $state, 'reason' => $reason, 'message' => $message, 'vars' => $vars];
        };

        // Le contexte que reçoivent les règles extraites. Il est bâti une fois, ici, parce
        // que c'est le collecteur qui a le droit d'aller chercher ce qu'elles n'ont pas le
        // droit de demander : la base, l'horloge, le réseau.
        $contexte = new \Uptimeez\Regle\Contexte(
            sonde: $mon,
            reponse: $res,
            detecteurs: [],
            manuel: $manual,
            etatPrecedent: isset($mon['status']) ? (string) $mon['status'] : null,
        );

        // ---- 1. Couche réseau ---------------------------------------------
        if (!$res->ok || $res->status === 0) {
            $code = $res->errorCode ?: 'NET_ERROR';

            // Échec lié au certificat : on interroge le serveur en TLS permissif
            // pour donner la vraie cause, et on n'affiche qu'elle.
            $sslDiag = null;
            if ($https && in_array($code, ['SSL_INVALID', 'SSL_HANDSHAKE'], true) && (int)$mon['check_ssl'] === 1) {
                $ssl = Ssl::inspect(host_of($mon['url']), self::portOf($mon['url']), (int)$mon['timeout_sec']);
                $details['ssl'] = $ssl;
                if ($ssl['checked'] && $ssl['error']) {
                    $sslDiag = ['code' => $ssl['code'] ?: 'SSL_INVALID', 'msg' => $ssl['error']];
                }
            }

            if ($sslDiag) {
                $due = isset($details['ssl']['expires_at']) && $details['ssl']['expires_at']
                    ? date('d/m/Y', strtotime((string)$details['ssl']['expires_at'])) : '';
                if ($due !== '') {
                    $note('down', $sslDiag['code'], '{reason} (échéance {date})',
                          ['reason' => $sslDiag['msg'], 'date' => $due]);
                } else {
                    $note('down', $sslDiag['code'], $sslDiag['msg']);
                }
            } else {
                // Le message reste en français ; la trace curl brute part dans les
                // détails techniques, consultables sur la fiche.
                $note('down', $code, Http::errorLabel($code));
                if ($res->error) $details['net_error'] = str_cut((string)$res->error, 200);
            }
            return self::verdict($findings, $details, $events, $res);
        }

        // ---- 2. Code HTTP -------------------------------------------------
        $expect = (string)($mon['expect_status'] ?: '200-299');
        if (!self::statusMatches($res->status, $expect)) {
            $c = $res->status;
            $reason = match (true) {
                $c >= 500 => 'HTTP_5XX',
                $c === 429 => 'HTTP_429',
                $c === 404 => 'HTTP_404',
                $c === 403 => 'HTTP_403',
                $c === 401 => 'HTTP_401',
                $c >= 400 => 'HTTP_4XX',
                $c >= 300 => 'HTTP_3XX',
                default   => 'HTTP_UNEXPECTED',
            };
            [$label, $vars] = match ($reason) {
                'HTTP_5XX' => ['Erreur serveur {code} : le site ne répond plus correctement', ['code' => $c]],
                'HTTP_404' => ['Page introuvable (404)', []],
                'HTTP_403' => ['Accès interdit (403)', []],
                'HTTP_401' => ['Authentification requise (401)', []],
                'HTTP_429' => ['Trop de requêtes (429) : quota serveur atteint', []],
                'HTTP_4XX' => ['Erreur client {code}', ['code' => $c]],
                'HTTP_3XX' => ['Redirection inattendue ({code}) vers {target}',
                               ['code' => $c, 'target' => str_cut($res->finalUrl, 80)]],
                default    => ['Code HTTP inattendu : {code}, attendu {expected}',
                               ['code' => $c, 'expected' => $expect]],
            };
            $note('down', $reason, $label, $vars);
        }

        // ---- 3. Base de données (signatures + chaîne de preuve) -----------
        if ((int)$mon['check_db'] === 1 && $res->body !== '') {
            $db = Database::audit($res, $mon);
            if ($db['state'] !== 'ok') {
                $details['db'] = $db;
                if ($db['evidence']) {
                    $note('down', $db['reason'], '{reason} : « {evidence} »',
                          ['reason' => $db['message'], 'evidence' => $db['evidence']]);
                } else {
                    $note('down', $db['reason'], $db['message']);
                }
            }
        }

        // ---- 4. Chaîne attendue / interdite -------------------------------
        //
        // PREMIÈRE RÈGLE EXTRAITE, LE 2026-08-02. La chaîne de preuve vit désormais dans
        // src/Regle/ChaineDePreuve.php, avec son propre test. Le collecteur ne fait plus
        // que l'appeler et remettre son verdict au format qu'il connaît, ce qui permet
        // d'extraire les vingt-trois autres UNE PAR UNE sans jamais laisser le moteur à
        // moitié converti.
        if ($v = (new \Uptimeez\Regle\ChaineDePreuve())->evaluer($contexte)) {
            $findings[] = $v->enTableau();
        }
        // DEUXIÈME RÈGLE EXTRAITE, LE 2026-08-02 : src/Regle/ChaineInterdite.php.
        if ($v = (new \Uptimeez\Regle\ChaineInterdite())->evaluer($contexte)) {
            $findings[] = $v->enTableau();
        }

        // ---- 5. API JSON ---------------------------------------------------
        // TROISIÈME EXTRACTION, LE 2026-08-02 : src/Regle/ReponseJson.php. Elle emporte
        // TROIS verdicts d'un coup (JSON_INVALID, JSON_PATH, JSON_VALUE) parce qu'ils
        // forment une seule chaîne de décision : sans décodage il n'y a pas de champ à
        // chercher, sans champ il n'y a pas de valeur à comparer.
        if ($v = (new \Uptimeez\Regle\ReponseJson())->evaluer($contexte)) {
            $findings[] = $v->enTableau();
        }

        // ---- 6. Certificat SSL --------------------------------------------
        // QUATRIÈME EXTRACTION, LE 2026-08-02 : src/Regle/Certificat.php, trois verdicts.
        //
        // Ce qui restait ici s'est réduit à la question « où trouver les faits ». Une
        // inspection TLS coûte une connexion, on ne la refait donc pas à chaque passe :
        // au-delà de six heures, ou sur demande d'un humain, on rouvre la connexion ;
        // en deçà, on relit les colonnes écrites la dernière fois.
        //
        // Les deux chemins produisent DÉSORMAIS LA MÊME FORME, et c'est tout l'intérêt.
        // Ils portaient chacun leur copie des verdicts, et la copie en cache avait déjà
        // divergé : elle ne savait pas dire « invalide ». Un certificat au mauvais nom
        // d'hôte redevenait donc muet pendant six heures.
        if ($https && (int)$mon['check_ssl'] === 1) {
            $perime = !$mon['ssl_checked_at'] || strtotime((string)$mon['ssl_checked_at']) < time() - 21600;

            if ($perime || $manual) {
                $faitsCert = Ssl::inspect(host_of($mon['url']), self::portOf($mon['url']), (int)$mon['timeout_sec']);
                $details['ssl'] = $faitsCert;
            } else {
                // La base ne retient que le compte à rebours. On ne prétend donc pas
                // savoir si le certificat est valide : on dit ce qu'on sait, et la règle
                // se taira sur le reste plutôt que d'inventer.
                $faitsCert = [
                    'checked'    => true,
                    'valid'      => true,
                    'code'       => null,
                    'error'      => '',
                    'expires_at' => null,
                    'days_left'  => $mon['ssl_days_left'],
                ];
            }

            $v = (new \Uptimeez\Regle\Certificat())
                ->evaluer($contexte->avecDetecteur(\Uptimeez\Regle\Certificat::DETECTEUR, $faitsCert));

            if ($v) {
                $findings[] = $v->enTableau();
            }
        }

        // ---- 7. Feuilles de style -----------------------------------------
        $htmlOk = $res->status >= 200 && $res->status < 300 && $res->isHtml();
        if ((int)$mon['check_css'] === 1 && $htmlOk) {
            $cadence = max((int)$mon['interval_sec'], 900);
            $stale   = !$mon['css_checked_at'] || strtotime((string)$mon['css_checked_at']) < time() - $cadence;
            $budget  = $manual || self::$cssAudits < self::CSS_AUDITS_PER_PASS;
            if ($stale && $budget) self::$cssAudits++;
            if (($stale && $budget) || $manual) {
                $baseline = jdec($mon['css_baseline'] ?? null);
                $css = Css::audit($mon['url'], $res->body, $res, $baseline, [
                    'drop_pct' => (int)$mon['css_drop_pct'],
                    'timeout'  => (int)$mon['timeout_sec'],
                    'insecure' => (bool)$mon['ignore_ssl_errors'],
                    'ua'       => $mon['user_agent'] ?: null,
                ]);
                $details['css'] = $css;
                // Vitesse ressentie : même page, mêmes ressources déjà
                // téléchargées, donc aucune requête de plus sauf un HEAD sur
                // l'image du haut de page, qui est le seul poids introuvable
                // dans le HTML.
                $details['vitals'] = Vitals::analyse($mon['url'], $res->body,
                    ($css['metrics'] ?? []) + ['css_text' => (string)($css['css_text'] ?? '')],
                    $res, [
                        'head'     => true,
                        'timeout'  => (int)$mon['timeout_sec'],
                        'ua'       => $mon['user_agent'] ?: null,
                        'insecure' => (bool)$mon['ignore_ssl_errors'],
                    ]);
                // L'inventaire logiciel se lit dans le HTML déjà reçu : aucune
                // requête de plus, et la veille de sécurité s'appuie dessus.
                if (!empty($mon['site_id'])) {
                    $cms = $mon['site_cms']
                        ?? Db::val('SELECT cms FROM sites WHERE id = ?', [(int)$mon['site_id']]);
                    $details['stack'] = Vuln::record((int)$mon['id'], (int)$mon['site_id'],
                                                     $res->body, $cms !== null ? (string)$cms : null);
                }
                if ($css['state'] === 'broken') {
                    // UNE MISE EN PAGE CASSÉE N'EST PAS UN SITE HORS SERVICE.
                    //
                    // Ce cas rendait « down », c'est-à-dire le MÊME état qu'un serveur qui
                    // ne répond plus, qu'un 500, ou qu'une base de données morte. Or ici la
                    // page répond, le serveur va bien, le contenu est là : c'est
                    // l'apparence qui souffre. Confondre les deux a deux coûts.
                    //
                    // Pour le lecteur des alertes, « hors service » perd son sens : il finit
                    // par ouvrir un courriel rouge en s'attendant à un problème de style, et
                    // le jour où le serveur tombe vraiment, il ouvre avec la même
                    // nonchalance. Pour les statistiques, une panne de style entre dans le
                    // taux de disponibilité et le fausse : on annonce au client un site
                    // indisponible alors qu'il servait ses pages.
                    //
                    // La règle est donc : DOWN veut dire que le visiteur n'obtient pas la
                    // page (pas de réponse, code d'erreur, chaîne de preuve absente).
                    // Tout ce qui concerne l'apparence plafonne à DEGRADED, quelle que
                    // soit sa gravité interne. La gravité reste lisible dans le message et
                    // dans le code de cause, qui distingue toujours CSS_BROKEN de
                    // CSS_DEGRADED.
                    $note('degraded', 'CSS_BROKEN', 'Mise en page cassée : {detail}',
                          ['detail' => implode(' ', array_slice($css['messages'], 0, 3))]);
                } elseif ($css['state'] === 'warn') {
                    $note('degraded', 'CSS_DEGRADED', 'CSS dégradé : {detail}',
                          ['detail' => implode(' ', array_slice($css['messages'], 0, 2))]);
                }
                if ($css['changed']) {
                    $events[] = ['kind' => 'css_changed', 'message' => t('Les fichiers CSS ont changé, sans doute un déploiement.')];
                }
            } elseif (in_array($mon['css_state'] ?? '', ['broken', 'warn'], true)) {
                // Entre deux analyses, le dernier verdict CSS reste valable :
                // sans cela une mise en page cassée « guérirait » toute seule
                // à la vérification suivante alors que rien n'a été corrigé.
                $prev = jdec($mon['css_detail'] ?? null);
                $why = implode(' ', array_slice($prev['messages'] ?? [], 0, 2))
                     ?: t('anomalie détectée à la dernière analyse');
                if (!empty($mon['css_checked_at'])) {
                    $why .= ' ' . t('(analyse du {date})',
                                    ['date' => date('d/m H:i', strtotime((string)$mon['css_checked_at']))]);
                }
                if ($mon['css_state'] === 'broken') {
                    // Même plafond que ci-dessus : l'apparence ne fait pas un hors service.
                    $note('degraded', 'CSS_BROKEN', 'Mise en page cassée : {detail}', ['detail' => $why]);
                } else {
                    $note('degraded', 'CSS_DEGRADED', 'CSS dégradé : {detail}', ['detail' => $why]);
                }
            }
        }

        // ---- 8. Indexabilité (utile en agence : un noindex oublié) --------
        // SIXIÈME EXTRACTION, LE 2026-08-02 : src/Regle/Indexabilite.php. La condition
        // « ai-je une page HTML analysable » est partie dans le contexte, où les quatre
        // règles du CSS viendront la chercher au lieu d'en faire cinq copies.
        if ($v = (new \Uptimeez\Regle\Indexabilite())->evaluer($contexte)) {
            $findings[] = $v->enTableau();
        }

        // ---- 9. Lenteur ----------------------------------------------------
        // CINQUIÈME EXTRACTION, LE 2026-08-02 : src/Regle/Lenteur.php.
        if ($v = (new \Uptimeez\Regle\Lenteur())->evaluer($contexte)) {
            $findings[] = $v->enTableau();
        }

        // ---- 10. Mot surveillé (mise à jour de page) ----------------------
        $watch = trim((string)($mon['watch_string'] ?? ''));
        if ($watch !== '' && $res->body !== '' && $complete) {
            $present = self::containsAny($res->body, $watch);
            $prev    = $mon['watch_state'] ?? null;
            $state   = $present ? 'present' : 'absent';
            if ($prev !== null && $prev !== $state) {
                $mode = $mon['watch_mode'] ?: 'appear';
                $wanted = ($mode === 'appear' && $present) || ($mode === 'disappear' && !$present);
                $events[] = [
                    'kind'    => $wanted ? 'watch_hit' : 'watch_change',
                    'message' => $present
                        ? t('Le texte « {string} » est apparu sur {url}',
                            ['string' => str_cut($watch, 50), 'url' => str_cut($mon['url'], 60)])
                        : t('Le texte « {string} » a disparu de {url}',
                            ['string' => str_cut($watch, 50), 'url' => str_cut($mon['url'], 60)]),
                    'notify'  => $wanted,
                ];
            }
            $details['watch_state'] = $state;
        }

        // ---- 11. Modification de contenu ---------------------------------
        if ((int)($mon['check_content'] ?? 0) === 1 && $htmlOk && $complete) {
            $hash = self::contentHash($res->body);
            $details['content_hash'] = $hash;
            if (!empty($mon['content_hash']) && $mon['content_hash'] !== $hash) {
                $events[] = ['kind' => 'content_changed',
                             'message' => 'Le contenu de la page a changé', 'notify' => true];
            }
        }

        return self::verdict($findings, $details, $events, $res);
    }

    /**
     * Priorité d'affichage à gravité égale : on montre la cause la plus
     * actionnable, pas la première rencontrée.
     */
    private const REASON_PRIORITY = [
        'DNS' => 100, 'CONNECT' => 99, 'CONNECT_RESET' => 98, 'TIMEOUT' => 97,
        'SSL_EXPIRED' => 95, 'SSL_INVALID' => 94, 'SSL_HANDSHAKE' => 93, 'REDIRECT_LOOP' => 92,
        'DB_DOWN' => 90, 'APP_ERROR' => 89, 'HTTP_5XX' => 88,
        // Une erreur de base AFFICHÉE sur une page qui répond normalement :
        // à montrer en premier parmi les signaux dégradés, c'est le plus grave
        // d'entre eux, mais ce n'est pas une panne.
        'DB_ERROR_VISIBLE' => 58, 'APP_ERROR_VISIBLE' => 57,
        'STRING_MISSING' => 80, 'STRING_FORBIDDEN' => 79,
        'HTTP_404' => 75, 'HTTP_403' => 74, 'HTTP_401' => 73, 'HTTP_429' => 72,
        'HTTP_4XX' => 71, 'HTTP_3XX' => 70, 'HTTP_UNEXPECTED' => 69,
        'JSON_INVALID' => 65, 'JSON_PATH' => 64, 'JSON_VALUE' => 63,
        'CSS_BROKEN' => 60,
        'SSL_SOON' => 50, 'CSS_DEGRADED' => 45, 'NOINDEX' => 40, 'SLOW' => 30,
        // Une lecture partielle est le moins actionnable des signaux dégradés :
        // elle dit « je n'ai pas pu vérifier », pas « quelque chose est cassé ».
        'BODY_TRUNCATED' => 20,
        // Repli de la couche réseau : il revient seul, la priorité n'arbitre
        // rien, mais un motif absent de cette table valait 0 sans le dire.
        'NET_ERROR' => 96, 'HEARTBEAT_LATE' => 85,
    ];

    private static function verdict(array $findings, array $details, array $events, Response $res): array
    {
        $state = 'up';
        foreach ($findings as $f) {
            if (self::SEVERITY[$f['state']] > self::SEVERITY[$state]) $state = $f['state'];
        }
        // Causes de la gravité retenue, triées par priorité d'affichage.
        $primary = array_values(array_filter($findings, fn($f) => $f['state'] === $state));
        usort($primary, fn($a, $b) => (self::REASON_PRIORITY[$b['reason'] ?? ''] ?? 0)
                                   <=> (self::REASON_PRIORITY[$a['reason'] ?? ''] ?? 0));
        $reason = $state === 'up' ? null : ($primary[0]['reason'] ?? null);
        // Le verdict retenu est UNE cause : la plus prioritaire. Joindre trois
        // phrases donnerait un texte intraduisible, figé dans la langue du cron.
        // Les autres causes ne sont pas perdues : elles restent dans les détails
        // techniques, avec leurs variables, et s'affichent sur la fiche.
        if ($state === 'up') {
            $msg  = 'Tout va bien';
            $vars = [];
        } else {
            $msg  = (string)($primary[0]['message'] ?? 'Tout va bien');
            $vars = (array)($primary[0]['vars'] ?? []);
        }

        return [
            'state'    => $state,
            'reason'   => $reason,
            'message'  => str_cut($msg, 400),
            'vars'     => $vars,
            'findings' => $findings,
            'details'  => $details,
            'events'   => $events,
            'response' => [
                'status' => $res->status, 'total_ms' => $res->totalMs, 'size' => $res->size,
                'final_url' => $res->finalUrl, 'redirects' => $res->redirects,
            ],
        ];
    }

    // =====================================================================
    // Persistance + moteur d'incidents
    // =====================================================================

    private static function persist(array $mon, Response $res, array $verdict, int $attempts): void
    {
        $id    = (int)$mon['id'];
        $state = $verdict['state'];
        $ts    = now();
        $det   = $verdict['details'];

        $cssState = $det['css']['state'] ?? null;
        $sslDays  = $det['ssl']['days_left'] ?? ($mon['ssl_days_left'] !== null ? (int)$mon['ssl_days_left'] : null);

        Db::insert('checks', [
            'monitor_id'    => $id,
            'ts'            => $ts,
            'state'         => $state,
            'reason_code'   => $verdict['reason'],
            'status_code'   => $res->status ?: null,
            'message'       => $verdict['message'],
            // Attention : une réponse en 0 ms (serveur local, cache) est une
            // mesure valide : la convertir en NULL la ferait disparaître des stats.
            'dns_ms'        => $res->status > 0 || $res->dnsMs > 0 ? $res->dnsMs : null,
            'connect_ms'    => $res->status > 0 || $res->connectMs > 0 ? $res->connectMs : null,
            'tls_ms'        => $res->tlsMs > 0 ? $res->tlsMs : null,
            'ttfb_ms'       => $res->status > 0 || $res->ttfbMs > 0 ? $res->ttfbMs : null,
            'total_ms'      => $res->status > 0 || $res->totalMs > 0 ? $res->totalMs : null,
            'size_bytes'    => $res->size ?: null,
            'redirects'     => $res->redirects,
            'final_url'     => $res->finalUrl !== $mon['url'] ? $res->finalUrl : null,
            'ssl_days_left' => $sslDays,
            'css_state'     => $cssState,
            'details'       => ($verdict['findings'] || isset($det['net_error'])) ? jenc([
                                   'findings' => array_map(
                                       fn($f) => [$f['state'], $f['reason'], str_cut($f['message'], 200),
                                                  (array)($f['vars'] ?? [])],
                                       $verdict['findings']),
                                   'net_error' => $det['net_error'] ?? null,
                               ]) : null,
            'attempts'      => $attempts,
        ]);

        // --- Mise à jour de la sonde ---------------------------------------
        // Le créneau remplace l'aléa : voir prochainPassage(), qui explique pourquoi un
        // « random_int » borné à 45 secondes ne dispersait rien sur un intervalle de
        // quinze minutes et ne garantissait aucun espacement.
        //
        // La sonde est passée par ici, donc son adresse VIENT d'être écrite quelques lignes
        // plus bas : on la pose dans le tableau transmis pour que la grappe soit calculée
        // sur l'adresse d'aujourd'hui et non sur celle de la passe précédente. Un site qui
        // change d'hébergeur rejoint sa nouvelle grappe au premier passage.
        $ipDuJour = $res->ip !== '' ? $res->ip : ($mon['last_ip'] ?? null);
        $upd = [
            'last_check_at'    => $ts,
            'next_check_at'    => date('Y-m-d H:i:s', self::prochainPassage(['last_ip' => $ipDuJour] + $mon)),
            'last_ms'          => $res->status > 0 || $res->totalMs > 0 ? $res->totalMs : null,
            'last_status_code' => $res->status ?: null,
            'last_ip'          => $res->ip !== '' ? $res->ip : ($mon['last_ip'] ?? null),
            'reason_code'      => $verdict['reason'],
            'last_message'      => $verdict['message'],
            'last_message_vars' => !empty($verdict['vars']) ? jenc($verdict['vars']) : null,
        ];
        if ($mon['status'] !== $state) {
            $upd['status']       = $state;
            $upd['status_since'] = $ts;
        }
        $upd['consecutive_fail'] = $state === 'down' ? ((int)$mon['consecutive_fail'] + 1) : 0;
        $upd['consecutive_ok']   = $state === 'up'   ? ((int)$mon['consecutive_ok'] + 1) : 0;

        if (isset($det['ssl']) && $det['ssl']['checked']) {
            $upd['ssl_checked_at'] = $ts;
            $upd['ssl_days_left']  = $det['ssl']['days_left'];
            $upd['ssl_issuer']     = $det['ssl']['issuer'] ? str_cut($det['ssl']['issuer'], 120) : null;
            $upd['ssl_expires_at'] = $det['ssl']['expires_at'];
        }
        if (isset($det['css'])) {
            $upd['css_checked_at'] = $ts;
            $upd['css_state']      = $det['css']['state'];
            $upd['css_detail']     = jenc([
                'messages' => $det['css']['messages'],
                'console'  => $det['css']['console'] ?? [],
                'metrics'  => self::slimCssMetrics($det['css']['metrics'] ?? []),
                'at'       => $ts,
            ]);
            // L'empreinte de référence n'est mémorisée que sur un état sain.
            $lock = (int)($mon['css_baseline_locked'] ?? 0) === 1;
            if ($det['css']['state'] === 'ok' && !$lock && !empty($det['css']['baseline'])) {
                $upd['css_baseline']    = jenc($det['css']['baseline']);
                $upd['css_baseline_at'] = $ts;
            }
            // Silhouette : la référence se prend sur un état sain, l'actuelle à
            // chaque analyse. C'est l'écart entre les deux qui parle au client.
            if (!empty($det['css']['silhouette'])) {
                $sig  = $det['css']['silhouette_sig'] ?? [];
                $refS = jdec($mon['silhouette_ref_sig'] ?? null);
                $upd['silhouette_now']     = $det['css']['silhouette'];
                $upd['silhouette_now_sig'] = jenc($sig);
                $upd['silhouette_at']      = $ts;
                if ($det['css']['state'] === 'ok' && !$lock) {
                    $upd['silhouette_ref']     = $det['css']['silhouette'];
                    $upd['silhouette_ref_sig'] = jenc($sig);
                    $upd['silhouette_ref_at']  = $ts;
                    $upd['silhouette_drift']   = 0;
                } elseif ($refS) {
                    $upd['silhouette_drift'] = (int)round(Silhouette::distance($refS, $sig) * 100);
                }
            }
        }
        if (isset($det['vitals'])) {
            $v = $det['vitals'];
            $upd['vitals_level']  = $v['level'];
            $upd['vitals_at']     = $ts;
            $upd['vitals_detail'] = jenc([
                'ttfb_ms' => $v['ttfb_ms'], 'ttfb_verdict' => $v['ttfb_verdict'],
                'blocking' => ['css' => $v['blocking']['css'], 'js' => $v['blocking']['js'],
                               'bytes' => $v['blocking']['bytes'],
                               'items' => array_slice($v['blocking']['items'], 0, 6)],
                'lcp_image' => $v['lcp_image'],
                'findings'  => $v['findings'],
            ]);
        }
        if (isset($det['watch_state'])) {
            $upd['watch_state'] = $det['watch_state'];
            if ($det['watch_state'] === 'present' && ($mon['watch_state'] ?? null) !== 'present') {
                $upd['watch_seen_at'] = $ts;
            }
        }
        if (isset($det['content_hash'])) {
            if (($mon['content_hash'] ?? null) !== $det['content_hash']) {
                $upd['content_changed_at'] = $ts;
            }
            $upd['content_hash']    = $det['content_hash'];
            $upd['content_hash_at'] = $ts;
        }

        Db::update('monitors', $upd, 'id = :__id', ['__id' => $id]);

        // Le seuil de lenteur se recale sur les mesures réelles de cette sonde.
        if ($state !== 'down') Tune::slowThreshold(array_merge($mon, ['slow_ms' => $mon['slow_ms']]));

        // --- Incidents & notifications -------------------------------------
        if ($res->ip !== '') $mon['last_ip'] = $res->ip;
        self::applyIncident($mon, $state, $verdict);

        foreach ($verdict['events'] as $ev) {
            Db::insert('events', [
                'monitor_id' => $id, 'ts' => $ts, 'kind' => $ev['kind'],
                'message' => str_cut($ev['message'], 300), 'details' => null, 'seen' => 0,
            ]);
            if (!empty($ev['notify'])) {
                Notifier::sendEvent($mon, $ev['kind'], $ev['message']);
            }
        }
    }

    /** Ouverture / mise à jour / clôture d'incident. */
    private static function applyIncident(array $mon, string $state, array $verdict): void
    {
        $id   = (int)$mon['id'];
        $open = Db::one('SELECT * FROM incidents WHERE monitor_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
        $ts   = now();

        if ($state === 'up') {
            if ($open) {
                $dur = max(0, time() - strtotime((string)$open['started_at']));
                Db::update('incidents', ['ended_at' => $ts, 'duration_sec' => $dur], 'id = :__i', ['__i' => (int)$open['id']]);
                Notifier::sendRecovery($mon, $open + ['duration_sec' => $dur]);
            }
            return;
        }

        // UNE SEULE PASSE EN ÉCHEC N'OUVRE PLUS D'INCIDENT : ON CONFIRME D'ABORD.
        //
        // Le collecteur relançait déjà les échecs réseau et les 5xx jusqu'à « retries + 1 »
        // fois, mais IMMÉDIATEMENT, dans la même seconde. Ça attrape un paquet perdu, et
        // rien d'autre : un redémarrage de PHP-FPM, une purge de cache ou un pic de charge
        // durent de une à dix secondes, et les trois tentatives immédiates tombent toutes
        // dedans. L'incident s'ouvrait donc, et le client recevait une alerte pour une
        // panne qui n'existait plus quand il ouvrait son courriel.
        //
        // POURQUOI PAS TROIS PAUSES DE 5, 15 ET 30 SECONDES, qui étaient l'autre option.
        // Elles immobiliseraient un ouvrier cinquante secondes, alors que la passe entière
        // dispose de cinquante secondes de budget : une seule sonde instable mangerait la
        // passe, et les douze autres sondes de la minute passeraient à la suivante. On
        // paierait une fausse alerte par un vrai retard de détection sur tout le reste.
        //
        // CE QU'ON FAIT À LA PLACE. Le premier échec ne crée rien : il replanifie la sonde
        // dans trente secondes et compte. L'incident n'est ouvert qu'au SECOND échec
        // consécutif. Un contrôle de plus, aucun ouvrier bloqué, trente secondes de délai
        // sur un intervalle de quinze minutes.
        //
        // La colonne « consecutive_fail » existait déjà et était tenue à jour depuis
        // toujours par persist(). Personne ne la lisait. C'est le troisième cas cette
        // semaine d'une information que le moteur collecte et n'utilise pas.
        //
        // L'AGGRAVATION N'ATTEND PAS. Une sonde déjà en incident qui empire est traitée
        // plus bas, sans confirmation : la panne est établie, retarder son aggravation
        // n'apporterait rien et coûterait trente secondes sur le cas le plus grave.
        // LA CONDITION PORTE SUR L'ÉTAT PRÉCÉDENT, ET NON SUR « consecutive_fail ».
        //
        // Première version : « consecutive_fail < 1 ». Elle marchait pour les pannes et
        // cassait tout le reste, parce que ce compteur n'est incrémenté que sur « down »
        // (voir persist()). Une sonde DÉGRADÉE le laissait donc à zéro pour toujours, et
        // son incident ne se serait jamais ouvert. Le parcours de bout en bout l'a
        // attrapé en une passe, là où le selftest était vert : un compteur juste, une
        // condition juste, et une combinaison qui rend le produit muet sur une famille
        // entière de cas.
        //
        // « $mon » porte l'état AVANT cette passe, puisque persist() écrit le nouveau
        // plus bas. La question posée est donc la bonne : « est-ce la première fois qu'on
        // voit ce problème ? » Elle vaut pour « down » comme pour « degraded », sans
        // dépendre d'un compteur qui ne connaît que l'un des deux.
        $premiereObservation = ($mon['status'] ?? 'up') === 'up';

        if (!$open && $premiereObservation) {
            Db::update('monitors', [
                'next_check_at' => date('Y-m-d H:i:s', time() + self::CONFIRMATION_SEC),
            ], 'id = :__id', ['__id' => $id]);

            return;
        }

        if (!$open) {
            $incidentId = Db::insert('incidents', [
                'monitor_id'    => $id,
                'severity'      => $state,
                'reason_code'   => $verdict['reason'],
                'message'       => str_cut($verdict['message'], 400),
                'message_vars'  => !empty($verdict['vars']) ? jenc($verdict['vars']) : null,
                'started_at'    => $ts,
                'checks_failed' => 1,
            ]);
            $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [$incidentId]);
            // On n'alerte pas tout de suite : les pannes de la passe sont mises en
            // file puis corrélées, pour qu'un serveur entier ne génère qu'un seul
            // message au lieu d'un par site.
            if ($inc) self::$pendingAlerts[] = ['monitor' => $mon, 'incident' => $inc];
            return;
        }

        // Incident en cours : on aggrave si besoin, on relance l'alerte à intervalle.
        //
        // Le motif, le message et les variables du message vont toujours ensemble.
        // Ils partaient séparément : un changement de cause à gravité égale
        // réécrivait le message sans toucher au motif, si bien que l'incident
        // affichait « la chaîne de contrôle est absente » avec le diagnostic et le
        // remède d'une erreur 500. Et les variables n'étaient jamais réécrites,
        // donc le nouveau message était rempli avec les valeurs de l'ancien :
        // « Erreur serveur 404 » là où le serveur avait répondu 503.
        //
        // Une gravité qui BAISSE ne réécrit rien : un incident se raconte par son
        // pire moment, et l'état courant de la sonde est ailleurs.
        $upd = ['checks_failed' => (int)$open['checks_failed'] + 1];
        $escalated = false;
        $describe  = false;
        if (self::SEVERITY[$state] > self::SEVERITY[$open['severity']]) {
            $upd['severity'] = $state;
            $escalated = true;
            $describe  = true;
        } elseif (self::SEVERITY[$state] === self::SEVERITY[$open['severity']]
                  && $open['reason_code'] !== $verdict['reason']) {
            $describe = true;
        }
        if ($describe) {
            $upd['reason_code']  = $verdict['reason'];
            $upd['message']      = str_cut($verdict['message'], 400);
            $upd['message_vars'] = !empty($verdict['vars']) ? jenc($verdict['vars']) : null;
        }
        Db::update('incidents', $upd, 'id = :__i', ['__i' => (int)$open['id']]);

        $resend = (int)Config::get('notify.resend_after_min', 60);
        // Si la première alerte n'est jamais partie (canal HS, heures calmes),
        // on repart de l'ouverture de l'incident pour ne pas rester muet.
        $last = $open['last_notified_at']
            ? strtotime((string)$open['last_notified_at'])
            : strtotime((string)$open['started_at']);
        if ($escalated || ($resend > 0 && $last > 0 && time() - $last >= $resend * 60)) {
            $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [(int)$open['id']]);
            if ($inc && !$inc['ack_at']) Notifier::sendIncident($mon, $inc, $escalated ? 'aggrave' : 'rappel');
        }
    }

    private static function persistPaused(array $mon): void
    {
        Db::update('monitors', [
            'status'        => 'paused',
            'last_check_at' => now(),
            // Même règle que le chemin nominal. Une sonde en pause qui repartait sur
            // « maintenant + intervalle » revenait hors de son créneau, et une reprise de
            // masse après une pause générale les faisait toutes repartir ensemble.
            'next_check_at' => date('Y-m-d H:i:s', self::prochainPassage($mon)),
        ], 'id = :__id', ['__id' => (int)$mon['id']]);
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    public static function requestOptions(array $mon): array
    {
        $headers = [];
        foreach (jdec($mon['request_headers'] ?? null) as $k => $v) $headers[(string)$k] = (string)$v;
        return [
            'method'   => $mon['method'] ?: 'GET',
            'body'     => $mon['request_body'] ?? null,
            'headers'  => $headers,
            'timeout'  => (int)($mon['timeout_sec'] ?: 15),
            'follow'   => (int)$mon['follow_redirects'] === 1,
            'insecure' => (int)$mon['ignore_ssl_errors'] === 1,
            'ua'       => $mon['user_agent'] ?: null,
            'auth'     => ($mon['auth_user'] ?? '') !== '' ? $mon['auth_user'] . ':' . ($mon['auth_pass'] ?? '') : null,
            'maxBody'  => $mon['kind'] === 'api' ? 500000 : Http::MAX_BODY,
        ];
    }

    private static function worthRetrying(Response $res): bool
    {
        if (!$res->ok || $res->status === 0) return true;
        return $res->status >= 500 || $res->status === 429;
    }

    public static function isPaused(array $mon): bool
    {
        if ((int)$mon['enabled'] !== 1) return true;
        if (!empty($mon['paused_until']) && strtotime((string)$mon['paused_until']) > time()) return true;
        return self::inMaintenance((string)($mon['maintenance'] ?? ''));
    }

    /** Fenêtre de maintenance : « 02:00-04:00 » ou « mon-fri 02:00-04:00 » ou « sat,sun 01:00-06:00 ». */
    public static function inMaintenance(string $spec): bool
    {
        $spec = strtolower(trim($spec));
        if ($spec === '') return false;
        $days = null;
        if (preg_match('~^([a-z,\-]+)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$~', $spec, $m)) {
            $days = $m[1]; $from = $m[2]; $to = $m[3];
        } elseif (preg_match('~^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$~', $spec, $m)) {
            $from = $m[1]; $to = $m[2];
        } else {
            return false;
        }
        if ($days !== null) {
            $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
                    'lun' => 1, 'mar' => 2, 'mer' => 3, 'jeu' => 4, 'ven' => 5, 'sam' => 6, 'dim' => 7];
            $today = (int)date('N');
            $ok = false;
            foreach (explode(',', $days) as $chunk) {
                if (preg_match('~^([a-z]{3})-([a-z]{3})$~', $chunk, $mm)) {
                    $a = $map[$mm[1]] ?? null; $b = $map[$mm[2]] ?? null;
                    if ($a && $b && $today >= $a && $today <= $b) { $ok = true; break; }
                } elseif (isset($map[$chunk]) && $map[$chunk] === $today) { $ok = true; break; }
            }
            if (!$ok) return false;
        }
        $nowMin  = (int)date('H') * 60 + (int)date('i');
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));
        $a = $fh * 60 + $fm; $b = $th * 60 + $tm;
        return $a <= $b ? ($nowMin >= $a && $nowMin <= $b) : ($nowMin >= $a || $nowMin <= $b);
    }

    /** « 200 », « 200-299 », « 2xx », « 200,301,302 ». */
    /**
     * Le code reçu correspond-il à la spécification attendue ?
     *
     * Formes acceptées : « 200 », « 200-299 », « 2xx », et toute combinaison
     * séparée par une virgule ou une espace. Une spécification vide, ou qui ne
     * contient aucune forme exploitable, retombe sur le comportement par défaut :
     * sinon une faute de frappe (« 200 OK ») déclarait le site hors service pour
     * toujours, avec un message que personne ne relie à la cause.
     */
    public static function statusMatches(int $status, string $spec): bool
    {
        $spec = trim($spec);
        if ($spec === '' || !self::validStatusSpec($spec)) return $status >= 200 && $status < 400;
        foreach (preg_split('~[,\s]+~', $spec) ?: [] as $part) {
            $part = strtolower(trim($part));
            if ($part === '') continue;
            if (preg_match('~^(\d)xx$~', $part, $m)) {
                if (intdiv($status, 100) === (int)$m[1]) return true;
            } elseif (preg_match('~^(\d{3})\s*-\s*(\d{3})$~', $part, $m)) {
                if ($status >= (int)$m[1] && $status <= (int)$m[2]) return true;
            } elseif (ctype_digit($part)) {
                if ($status === (int)$part) return true;
            }
        }
        return false;
    }

    /**
     * Recherche insensible à la casse ; « a|b » = l'une des deux suffit.
     * Tolère les entités HTML (&eacute;) et les apostrophes typographiques,
     * dans les deux sens : la chaîne saisie n'a pas à être encodée comme la page.
     */
    /**
     * La spécification de codes attendus est-elle exploitable ?
     *
     * Sert à deux endroits : refuser une saisie absurde à l'enregistrement, et
     * ne pas se fier à une valeur invalide déjà en base.
     */
    public static function validStatusSpec(string $spec): bool
    {
        $spec = trim($spec);
        if ($spec === '') return true;                    // vide = comportement par défaut
        $parts = array_filter(preg_split('~[,\s]+~', $spec) ?: []);
        if (!$parts) return false;
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            $ok = preg_match('~^\d[xX]{2}$~', $part)
               || preg_match('~^\d{3}\s*-\s*\d{3}$~', $part)
               || (ctype_digit($part) && strlen($part) === 3);
            if (!$ok) return false;
        }
        return true;
    }

    public static function containsAny(string $haystack, string $needles): bool
    {
        $decoded = null;   // décodage du corps calculé au besoin seulement

        foreach (explode('|', $needles) as $n) {
            $n = trim($n);
            if ($n === '') continue;
            if (stripos($haystack, $n) !== false) return true;

            // Variantes de la chaîne recherchée
            $variants = [
                str_replace(["'", '&nbsp;'], ['’', ' '], $n),
                html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($n, ENT_QUOTES, 'UTF-8'),
            ];
            foreach ($variants as $alt) {
                if ($alt !== $n && $alt !== '' && stripos($haystack, $alt) !== false) return true;
            }

            // Page encodée en entités : on décode le corps une seule fois.
            if (str_contains($haystack, '&')) {
                $decoded ??= html_entity_decode($haystack, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (stripos($decoded, $n) !== false) return true;
                $soft = str_replace("'", '’', $n);
                if ($soft !== $n && stripos($decoded, $soft) !== false) return true;
            }
        }
        return false;
    }

    /** Chemin simple type « data.status » ou « 0.name ». */
    public static function jsonPath(mixed $json, string $path): mixed
    {
        $node = $json;
        foreach (explode('.', trim($path, '. ')) as $seg) {
            if ($seg === '') continue;
            if (is_array($node) && array_key_exists($seg, $node)) $node = $node[$seg];
            elseif (is_array($node) && ctype_digit($seg) && array_key_exists((int)$seg, $node)) $node = $node[(int)$seg];
            else return null;
        }
        return is_scalar($node) || $node === null ? $node : jenc($node);
    }

    /** Empreinte du contenu utile (sans scripts, styles, jetons ni horodatages). */
    public static function contentHash(string $html): string
    {
        $s = preg_replace('~<script\b.*?</script>~is', '', $html) ?? $html;
        $s = preg_replace('~<style\b.*?</style>~is', '', $s) ?? $s;
        $s = preg_replace('~<!--.*?-->~s', '', $s) ?? $s;
        $s = preg_replace('~<(link|meta)\b[^>]*>~i', '', $s) ?? $s;
        $s = preg_replace('~(nonce|csrf|token|_wpnonce|ver)=["\']?[\w\-]+~i', '', $s) ?? $s;
        $s = strip_tags($s);
        $s = preg_replace('~\d{1,2}[:/]\d{2}(?::\d{2})?~', '', $s) ?? $s; // heures
        $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
        return sha1(trim($s));
    }

    private static function portOf(string $url): int
    {
        $p = parse_url($url);
        return (int)($p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80));
    }

    /** On ne stocke pas tout le détail CSS : juste ce qui sert à l'affichage. */
    private static function slimCssMetrics(array $m): array
    {
        $assets = [];
        foreach (($m['assets'] ?? []) as $a) {
            $assets[] = [
                'url' => $a['url'], 'kind' => $a['kind'] ?? 'css', 'status' => $a['status'],
                'bytes' => $a['bytes'], 'issue' => $a['issue'], 'note' => $a['note'],
                'soft' => $a['soft'] ?? false,
            ];
        }
        // Les ressources en échec d'abord : c'est ce qu'on veut lire en premier.
        usort($assets, fn($a, $b) => (empty($a['issue']) <=> empty($b['issue'])) ?: ($a['kind'] <=> $b['kind']));

        return [
            'sheets_declared' => $m['sheets_declared'] ?? 0,
            'sheets_ok'       => $m['sheets_ok'] ?? 0,
            'sheets_failed'   => $m['sheets_failed'] ?? 0,
            'js_declared'     => $m['js_declared'] ?? 0,
            'js_ok'           => $m['js_ok'] ?? 0,
            'js_failed'       => $m['js_failed'] ?? 0,
            'fonts_checked'   => $m['fonts_checked'] ?? 0,
            'fonts_failed'    => $m['fonts_failed'] ?? 0,
            'css_bytes'       => $m['css_bytes'] ?? 0,
            'rules'           => $m['rules'] ?? 0,
            'media_queries'   => $m['media_queries'] ?? 0,
            'layout_score'    => $m['layout_score'] ?? 0,
            'coverage'        => $m['coverage'] ?? null,
            'classes_missing' => array_slice($m['classes_missing'] ?? [], 0, 8),
            'inline_bytes'    => $m['inline_bytes'] ?? 0,
            'hidden_nodes'    => $m['hidden_nodes'] ?? 0,
            'hidden_risk'     => $m['hidden_risk'] ?? false,
            'assets'          => array_slice($assets, 0, 30),
        ];
    }

    /** Rafraîchit expiration de domaine (une fois par jour, sondes principales). */
    public static function refreshDomains(int $limit = 10): int
    {
        $rows = Db::all(
            "SELECT id, url, domain_expires_at FROM monitors
             WHERE enabled = 1 AND role = 'primary'
             ORDER BY (domain_expires_at IS NULL) DESC, domain_expires_at ASC LIMIT " . max(1, $limit)
        );
        $n = 0;
        foreach ($rows as $r) {
            $info = DomainExpiry::lookup(host_of((string)$r['url']));
            if (!$info) continue;
            Db::update('monitors', ['domain_expires_at' => $info['expires_at']], 'id = :__i', ['__i' => (int)$r['id']]);
            if ($info['days_left'] <= 30) {
                $exists = Db::val("SELECT 1 FROM events WHERE monitor_id = ? AND kind = 'domain_soon' AND ts > ?",
                    [(int)$r['id'], date('Y-m-d H:i:s', time() - 7 * 86400)]);
                if (!$exists) {
                    Db::insert('events', ['monitor_id' => (int)$r['id'], 'ts' => now(), 'kind' => 'domain_soon',
                        'message' => tn((int)$info['days_left'],
                            'Le domaine {domain} expire dans un jour',
                            'Le domaine {domain} expire dans {n} jours', ['domain' => $info['domain']]),
                        'details' => jenc($info), 'seen' => 0]);
                    $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [(int)$r['id']]);
                    if ($mon) Notifier::sendEvent($mon, 'domain_soon',
                        tn((int)$info['days_left'], 'Le domaine {domain} expire dans un jour',
                           'Le domaine {domain} expire dans {n} jours', ['domain' => $info['domain']]));
                }
            }
            $n++;
        }
        return $n;
    }
}
