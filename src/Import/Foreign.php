<?php
declare(strict_types=1);

namespace Uptimeez\Import;

/**
 * Reprise d'un parc surveillé ailleurs.
 *
 * Le vrai frein au changement d'outil n'est pas le prix, c'est la soirée à
 * ressaisir quarante sondes. Cette classe lit l'export de l'outil qu'on quitte
 * et en tire des sondes Uptimeez, sans rien demander à personne.
 *
 * Cinq sources reconnues, choisies parce que ce sont celles qu'on rencontre :
 * UptimeRobot, Uptime Kuma, Better Stack, Pingdom, Site24x7. Plus un CSV
 * générique, pour tout le reste et pour les tableurs faits à la main.
 *
 * Trois règles, tenues sans exception :
 *
 *   1. **Ce qui ne se traduit pas est dit, pas jeté.** Une sonde de port TCP ou
 *      un ping ICMP n'a pas d'équivalent ici : elle apparaît dans la liste des
 *      écartées, avec la raison. Un import qui perd silencieusement six sondes
 *      sur quarante est pire qu'un import qui refuse.
 *   2. **Rien n'est inventé.** Un intervalle absent de l'export reste absent :
 *      c'est la valeur par défaut d'Uptimeez qui s'applique, et l'écran le dit.
 *      Aucune sonde n'est créée avec une cadence tirée au hasard.
 *   3. **La configuration, jamais l'historique.** Uptimeez n'importe pas les
 *      mesures passées : elles ont été prises par un autre outil, avec d'autres
 *      seuils, depuis un autre réseau. Les afficher comme les siennes serait un
 *      mensonge. Le compteur de disponibilité repart donc de zéro, et c'est
 *      écrit avant l'import.
 *
 * Le format n'est pas demandé à l'utilisateur : il est reconnu. Coller le
 * contenu ou déposer le fichier suffit.
 */
final class Foreign
{
    /** Sources reconnues, dans l'ordre où on tente de les reconnaître. */
    public const SOURCES = [
        'uptimerobot' => 'UptimeRobot',
        'kuma'        => 'Uptime Kuma',
        'betterstack' => 'Better Stack',
        'pingdom'     => 'Pingdom',
        'site24x7'    => 'Site24x7',
        'csv'         => 'CSV',
    ];

    /** Un export raisonnable tient largement dedans ; au-delà, on refuse. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /** Plafond de sondes reprises en une fois. */
    private const MAX_ROWS = 500;

    // =====================================================================
    // Reconnaissance
    // =====================================================================
    /**
     * Quel outil a produit ce contenu ?
     *
     * La détection porte sur des marqueurs de structure, jamais sur le nom du
     * fichier : un export renommé reste reconnaissable, et un fichier qui
     * s'appelle « uptimerobot.json » sans en être un ne trompe personne.
     */
    public static function detect(string $raw): ?string
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
        if ($raw === '') return null;

        if ($raw[0] === '{' || $raw[0] === '[') {
            $j = json_decode($raw, true);
            if (!is_array($j)) return null;
            // UptimeRobot : enveloppe « stat » et liste « monitors » typée.
            if (isset($j['monitors']) && is_array($j['monitors'])) {
                $first = $j['monitors'][0] ?? [];
                if (isset($first['friendly_name']) || isset($j['stat'])) return 'uptimerobot';
                if (isset($first['type']) && is_string($first['type'])) return 'kuma';
            }
            // Uptime Kuma : sauvegarde complète, monitorList indexée par identifiant.
            if (isset($j['monitorList']) && is_array($j['monitorList'])) return 'kuma';
            // Better Stack : JSON:API, attributs sous « attributes ».
            if (isset($j['data'][0]['attributes']['monitor_type'])
                || isset($j['data'][0]['attributes']['pronounceable_name'])) return 'betterstack';
            // Site24x7 : « display_name » et « monitor_type » en majuscules.
            if (isset($j['data'][0]['display_name']) || isset($j['data'][0]['website'])) return 'site24x7';
            // Pingdom : liste « checks », ou une seule sonde sous « check ».
            if (isset($j['checks']) && is_array($j['checks'])) return 'pingdom';
            if (isset($j['check']['hostname']) || isset($j['check']['type'])) return 'pingdom';
            return null;
        }

        // Un CSV : au moins une virgule ou un point-virgule sur la première ligne,
        // et un en-tête qui parle d'adresse.
        $head = strtolower(trim((string)strtok($raw, "\r\n")));
        if ($head !== '' && preg_match('~[,;\t]~', $head)
            && preg_match('~\b(url|website|hostname|adresse|host|monitor|friendly[_ ]name|nom|name)\b~', $head)) {
            return 'csv';
        }
        return null;
    }

    // =====================================================================
    // Lecture
    // =====================================================================
    /**
     * Lit un export et rend des sondes prêtes pour l'aperçu d'import.
     *
     * @return array{source:?string,label:string,rows:array,skipped:array,errors:array}
     */
    public static function parse(string $raw, ?string $source = null): array
    {
        $out = ['source' => null, 'label' => '', 'rows' => [], 'skipped' => [], 'errors' => []];
        if (strlen($raw) > self::MAX_BYTES) {
            $out['errors'][] = t('Fichier trop volumineux : {max} au maximum.',
                                 ['max' => human_bytes(self::MAX_BYTES)]);
            return $out;
        }
        $src = $source ?: self::detect($raw);
        if ($src === null) {
            $out['errors'][] = t('Format non reconnu. Les exports d\'UptimeRobot, Uptime Kuma, Better Stack, Pingdom et Site24x7 sont lus directement, ainsi qu\'un CSV avec une colonne d\'adresses.');
            return $out;
        }
        $out['source'] = $src;
        $out['label']  = self::SOURCES[$src] ?? $src;

        $res = match ($src) {
            'uptimerobot' => self::uptimeRobot($raw),
            'kuma'        => self::kuma($raw),
            'betterstack' => self::betterStack($raw),
            'pingdom'     => self::pingdom($raw),
            'site24x7'    => self::site24x7($raw),
            'csv'         => self::csv($raw),
            default       => ['rows' => [], 'skipped' => [], 'errors' => []],
        };
        $out['rows']    = array_slice($res['rows'], 0, self::MAX_ROWS);
        $out['skipped'] = $res['skipped'];
        $out['errors']  = array_merge($out['errors'], $res['errors']);
        if (count($res['rows']) > self::MAX_ROWS) {
            $out['errors'][] = t('{n} sondes lues, les {max} premières sont reprises.',
                ['n' => count($res['rows']), 'max' => self::MAX_ROWS]);
        }
        return $out;
    }

    // =====================================================================
    // UptimeRobot
    // =====================================================================
    /**
     * Export de l'API v2 (getMonitors) ou CSV du tableau de bord.
     *
     * Les types : 1 HTTP, 2 mot-clé, 3 ping, 4 port, 5 battement. Seuls les
     * deux premiers ont un équivalent direct. Le sens du mot-clé compte :
     * « exists » veut dire « alerter si le texte est là », donc une chaîne
     * interdite chez nous, et « not exists » une chaîne de contrôle.
     */
    private static function uptimeRobot(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $j = json_decode($raw, true);
        if (!is_array($j)) return self::csv($raw);   // l'export CSV du tableau de bord

        foreach (($j['monitors'] ?? []) as $i => $m) {
            $name = trim((string)($m['friendly_name'] ?? ''));
            $type = (int)($m['type'] ?? 1);
            if ($type === 3) { $skipped[] = self::skip($name, t('ping ICMP : {app} vérifie en HTTP')); continue; }
            if ($type === 4) {
                $skipped[] = self::skip($name, t('port TCP : sans équivalent, une sonde HTTP ne le remplace pas'));
                continue;
            }
            if ($type === 5) {
                $skipped[] = self::skip($name, t('battement : à recréer côté {app} pour obtenir une nouvelle URL de signal'));
                continue;
            }
            $url = normalize_url((string)($m['url'] ?? ''));
            if ($url === null) { $skipped[] = self::skip($name, t('adresse illisible')); continue; }

            $row = self::row($url, $name, $i + 1);
            if (isset($m['interval'])) $row['interval'] = self::interval((int)$m['interval']);
            // status 0 = en pause chez UptimeRobot.
            if (isset($m['status']) && (int)$m['status'] === 0) $row['enabled'] = 0;
            $kw = trim((string)($m['keyword_value'] ?? ''));
            if ($kw !== '') {
                // 1 = alerter si présent, 2 = alerter si absent.
                if ((int)($m['keyword_type'] ?? 2) === 1) $row['forbid'] = $kw;
                else $row['expect'] = $kw;
            }
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // Uptime Kuma
    // =====================================================================
    /**
     * Sauvegarde JSON (Réglages puis Sauvegarde). Deux formes existent selon
     * la version : « monitorList » indexée, ou « monitors » en tableau.
     */
    private static function kuma(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $j = json_decode($raw, true);
        if (!is_array($j)) { $errors[] = t('JSON illisible.'); return compact('rows', 'skipped', 'errors'); }
        $list = $j['monitorList'] ?? $j['monitors'] ?? [];
        if (!is_array($list)) $list = [];

        $i = 0;
        foreach ($list as $m) {
            $i++;
            if (!is_array($m)) continue;
            $name = trim((string)($m['name'] ?? ''));
            $type = strtolower((string)($m['type'] ?? 'http'));
            if (!in_array($type, ['http', 'keyword', 'json-query'], true)) {
                $skipped[] = self::skip($name, t('type « {type} » : {app} surveille en HTTP', ['type' => $type]));
                continue;
            }
            $url = normalize_url((string)($m['url'] ?? ''));
            if ($url === null) { $skipped[] = self::skip($name, t('adresse illisible')); continue; }

            $row = self::row($url, $name, $i);
            if (isset($m['interval'])) $row['interval'] = self::interval((int)$m['interval']);
            if (isset($m['active']) && !$m['active']) $row['enabled'] = 0;
            if (isset($m['maxretries'])) $row['retries'] = max(0, min(5, (int)$m['maxretries']));
            if (isset($m['timeout']))    $row['timeout'] = max(3, min(60, (int)$m['timeout']));
            $method = strtoupper(trim((string)($m['method'] ?? 'GET')));
            if (in_array($method, ['GET', 'HEAD', 'POST'], true)) $row['method'] = $method;
            $kw = trim((string)($m['keyword'] ?? ''));
            if ($kw !== '') {
                // invertKeyword : Kuma alerte quand le mot-clé EST présent.
                if (!empty($m['invertKeyword'])) $row['forbid'] = $kw;
                else $row['expect'] = $kw;
            }
            $codes = $m['accepted_statuscodes'] ?? null;
            if (is_array($codes) && $codes) {
                $spec = implode(',', array_map('strval', $codes));
                if (preg_match('~^[0-9,\- ]+$~', $spec)) $row['status_spec'] = $spec;
            }
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // Better Stack
    // =====================================================================
    /** Réponse de l'API monitors, au format JSON:API. */
    private static function betterStack(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $j = json_decode($raw, true);
        if (!is_array($j)) { $errors[] = t('JSON illisible.'); return compact('rows', 'skipped', 'errors'); }

        $i = 0;
        foreach (($j['data'] ?? []) as $item) {
            $i++;
            $a = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
            $name = trim((string)($a['pronounceable_name'] ?? $a['name'] ?? ''));
            $type = strtolower((string)($a['monitor_type'] ?? 'status'));
            if (in_array($type, ['ping', 'tcp', 'udp', 'smtp', 'pop', 'imap', 'dns', 'playwright'], true)) {
                $skipped[] = self::skip($name, t('type « {type} » : sans équivalent en surveillance HTTP', ['type' => $type]));
                continue;
            }
            $url = normalize_url((string)($a['url'] ?? ''));
            if ($url === null) { $skipped[] = self::skip($name, t('adresse illisible')); continue; }

            $row = self::row($url, $name, $i);
            if (isset($a['check_frequency'])) $row['interval'] = self::interval((int)$a['check_frequency']);
            if (!empty($a['paused'])) $row['enabled'] = 0;
            $method = strtoupper(trim((string)($a['request_method'] ?? 'get')));
            if (in_array($method, ['GET', 'HEAD', 'POST'], true)) $row['method'] = $method;
            $kw = trim((string)($a['required_keyword'] ?? ''));
            if ($kw !== '') {
                // keyword_absence : alerte quand le texte est présent.
                if ($type === 'keyword_absence') $row['forbid'] = $kw;
                else $row['expect'] = $kw;
            }
            $codes = $a['expected_status_codes'] ?? null;
            if (is_array($codes) && $codes) {
                $spec = implode(',', array_map('strval', $codes));
                if (preg_match('~^[0-9,\- ]+$~', $spec)) $row['status_spec'] = $spec;
            }
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // Pingdom
    // =====================================================================
    /**
     * Liste de contrôles de l'API. La résolution est en minutes, et l'adresse
     * se reconstruit à partir du nom d'hôte, du chemin et du chiffrement.
     */
    private static function pingdom(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $j = json_decode($raw, true);
        if (!is_array($j)) { $errors[] = t('JSON illisible.'); return compact('rows', 'skipped', 'errors'); }
        $list = $j['checks'] ?? (isset($j['check']) ? [$j['check']] : []);
        if (!is_array($list)) $list = [];

        $i = 0;
        foreach ($list as $c) {
            $i++;
            if (!is_array($c)) continue;
            $name = trim((string)($c['name'] ?? ''));
            // Le type est soit une chaîne (« http »), soit un objet typé.
            $type = 'http'; $http = [];
            if (is_string($c['type'] ?? null)) $type = strtolower((string)$c['type']);
            elseif (is_array($c['type'] ?? null)) {
                $type = strtolower((string)array_key_first($c['type']));
                $http = is_array($c['type'][$type] ?? null) ? $c['type'][$type] : [];
            }
            if (!in_array($type, ['http', 'httpcustom'], true)) {
                $skipped[] = self::skip($name, t('type « {type} » : sans équivalent en surveillance HTTP', ['type' => $type]));
                continue;
            }

            $host = trim((string)($c['hostname'] ?? $http['hostname'] ?? ''));
            $path = (string)($http['url'] ?? '/');
            $tls  = !isset($http['encryption']) || (bool)$http['encryption'];
            $url  = $host !== ''
                ? normalize_url(($tls ? 'https://' : 'http://') . $host . (str_starts_with($path, '/') ? $path : '/' . $path))
                : normalize_url($path);
            if ($url === null) { $skipped[] = self::skip($name, t('adresse illisible')); continue; }

            $row = self::row($url, $name, $i);
            // resolution est en minutes chez Pingdom.
            if (isset($c['resolution'])) $row['interval'] = self::interval((int)$c['resolution'] * 60);
            if (($c['status'] ?? '') === 'paused' || !empty($c['paused'])) $row['enabled'] = 0;
            $kw = trim((string)($http['shouldcontain'] ?? ''));
            if ($kw !== '') $row['expect'] = $kw;
            $no = trim((string)($http['shouldnotcontain'] ?? ''));
            if ($no !== '') $row['forbid'] = $no;
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // Site24x7
    // =====================================================================
    /** Réponse de l'API monitors. La fréquence est en secondes, en chaîne. */
    private static function site24x7(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $j = json_decode($raw, true);
        if (!is_array($j)) { $errors[] = t('JSON illisible.'); return compact('rows', 'skipped', 'errors'); }

        $i = 0;
        foreach (($j['data'] ?? []) as $m) {
            $i++;
            if (!is_array($m)) continue;
            $name = trim((string)($m['display_name'] ?? ''));
            $type = strtoupper((string)($m['monitor_type'] ?? 'URL'));
            if (!in_array($type, ['URL', 'HOMEPAGE', 'WEBSITE', 'REALBROWSER', 'RESTAPI'], true)) {
                $skipped[] = self::skip($name, t('type « {type} » : sans équivalent en surveillance HTTP', ['type' => $type]));
                continue;
            }
            $url = normalize_url((string)($m['website'] ?? $m['url'] ?? ''));
            if ($url === null) { $skipped[] = self::skip($name, t('adresse illisible')); continue; }

            $row = self::row($url, $name, $i);
            if (isset($m['check_frequency'])) $row['interval'] = self::interval((int)$m['check_frequency']);
            $state = strtolower((string)($m['status'] ?? ''));
            if ($state === '5' || $state === 'suspended') $row['enabled'] = 0;
            $kw = $m['matching_keyword'] ?? null;
            if (is_array($kw) && trim((string)($kw['value'] ?? '')) !== '') $row['expect'] = trim((string)$kw['value']);
            $un = $m['unmatching_keyword'] ?? null;
            if (is_array($un) && trim((string)($un['value'] ?? '')) !== '') $row['forbid'] = trim((string)$un['value']);
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // CSV générique
    // =====================================================================
    /**
     * CSV avec en-tête. Les colonnes sont reconnues par leur nom, en français
     * comme en anglais, et l'ordre n'a pas d'importance. C'est aussi le chemin
     * de secours pour les exports CSV des outils cités.
     */
    private static function csv(string $raw): array
    {
        $rows = []; $skipped = []; $errors = [];
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $lines = preg_split('~\R~', $raw) ?: [];
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if (!$lines) { $errors[] = t('Fichier vide.'); return compact('rows', 'skipped', 'errors'); }

        // Le séparateur est celui qui revient le plus souvent sur l'en-tête.
        $sep = ',';
        foreach ([';' => 0, "\t" => 0, ',' => 0] as $cand => $_) {
            if (substr_count($lines[0], $cand) > substr_count($lines[0], $sep)) $sep = $cand;
        }
        $head = array_map(fn($h) => self::normHead($h), str_getcsv($lines[0], $sep));
        $col = function (array $names) use ($head): ?int {
            foreach ($names as $n) {
                $i = array_search($n, $head, true);
                if ($i !== false) return (int)$i;
            }
            return null;
        };
        $iUrl  = $col(['url', 'website', 'hostname', 'host', 'adresse', 'lien', 'monitorurl', 'urlip']);
        $iName = $col(['name', 'nom', 'friendlyname', 'monitorname', 'displayname', 'title', 'libelle']);
        $iKw   = $col(['keyword', 'motcle', 'expect', 'shouldcontain', 'matchingkeyword', 'chainedecontrole']);
        $iInt  = $col(['interval', 'intervalle', 'frequency', 'checkfrequency', 'resolution', 'cadence']);
        $iAct  = $col(['active', 'enabled', 'status', 'etat', 'actif']);
        if ($iUrl === null) {
            $errors[] = t('Aucune colonne d\'adresse trouvée. Attendu un en-tête contenant « url », « website », « hostname » ou « adresse ».');
            return compact('rows', 'skipped', 'errors');
        }

        foreach (array_slice($lines, 1) as $n => $line) {
            $f = str_getcsv($line, $sep);
            $name = $iName !== null ? trim((string)($f[$iName] ?? '')) : '';
            $url = normalize_url((string)($f[$iUrl] ?? ''));
            if ($url === null) {
                $skipped[] = self::skip($name !== '' ? $name : t('ligne {n}', ['n' => $n + 2]),
                                        t('adresse illisible'));
                continue;
            }
            $row = self::row($url, $name, $n + 2);
            if ($iKw !== null && trim((string)($f[$iKw] ?? '')) !== '') $row['expect'] = trim((string)$f[$iKw]);
            if ($iInt !== null && is_numeric(trim((string)($f[$iInt] ?? '')))) {
                $v = (int)trim((string)$f[$iInt]);
                // Un chiffre sous 60 est presque toujours des minutes.
                $row['interval'] = self::interval($v < 60 ? $v * 60 : $v);
            }
            if ($iAct !== null) {
                $v = strtolower(trim((string)($f[$iAct] ?? '')));
                if (in_array($v, ['0', 'false', 'no', 'non', 'paused', 'pause', 'inactif', 'disabled'], true)) {
                    $row['enabled'] = 0;
                }
            }
            $rows[] = $row;
        }
        return compact('rows', 'skipped', 'errors');
    }

    // =====================================================================
    // Petits outils
    // =====================================================================
    /** Une ligne d'aperçu, au format attendu par Importer. */
    private static function row(string $url, string $name, int $line): array
    {
        return ['url' => $url, 'name' => str_cut($name, 180), 'expect' => '', 'line' => $line];
    }

    private static function skip(string $name, string $why): array
    {
        return ['name' => $name !== '' ? str_cut($name, 120) : t('sonde sans nom'), 'why' => $why];
    }

    /**
     * Cadence reprise telle quelle, dans les bornes acceptées.
     *
     * Une cadence de 60 secondes chez le voisin reste 60 secondes ici : c'est
     * un choix que quelqu'un a fait, pas une valeur à réinterpréter.
     */
    private static function interval(int $seconds): int
    {
        if ($seconds <= 0) return 0;                 // absent : le défaut s'appliquera
        return max(30, min(86400, $seconds));
    }

    /** « Friendly Name » et « friendly_name » désignent la même colonne. */
    private static function normHead(string $h): string
    {
        $h = strtolower(trim($h, " \t\"'"));
        // Les accents sont retirés pour que « intervalle » et « frequence »
        // se retrouvent quelle que soit la saisie.
        $h = strtr($h, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ô' => 'o', 'î' => 'i', 'ç' => 'c']);
        return (string)preg_replace('~[^a-z0-9]~', '', $h);
    }
}
