<?php
declare(strict_types=1);

/** Échappement HTML. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Traduit. La clé est la phrase française telle qu'écrite dans le code.
 * t('Sondes'), t('{n} sites suivis', ['n' => 12]).
 */
function t(string $msgid, array $vars = []): string
{
    return \Uptimeez\I18n::t($msgid, $vars);
}

/** Traduit puis échappe : la forme à utiliser par défaut dans les gabarits. */
function te(string $msgid, array $vars = []): string
{
    return e(\Uptimeez\I18n::t($msgid, $vars));
}

/** Traduit un pluriel : tn($n, 'un site', '{n} sites'). */
function tn(int $n, string $one, string $many, array $vars = []): string
{
    return \Uptimeez\I18n::n($n, $one, $many, $vars);
}

/** Idem, échappé. */
function tne(int $n, string $one, string $many, array $vars = []): string
{
    return e(\Uptimeez\I18n::n($n, $one, $many, $vars));
}

/**
 * Vrai en mode complet. Le mode simple masque les réglages fins et les
 * détails techniques : l'écran ne montre que ce sur quoi on peut agir.
 */
function expert(): bool
{
    return \Uptimeez\Ui::mode() === 'expert';
}

/**
 * Bulle d'aide « ? ». Le texte est traduit ici : les appelants passent la
 * phrase source. Rendu accessible : bouton, pas un simple title=.
 */
function hint(string $msgid, array $vars = []): string
{
    static $seq = 0;
    $id  = 'hint' . (++$seq);
    $txt = e(\Uptimeez\I18n::t($msgid, $vars));
    return '<span class="hint"><button type="button" class="hint-b" aria-label="'
         . te('Aide') . '" aria-describedby="' . $id . '" data-hint>?</button>'
         . '<span class="hint-t" id="' . $id . '" role="tooltip">' . $txt . '</span></span>';
}

/**
 * Le contenu à importer : le champ collé, ou le fichier déposé.
 *
 * Un export fait plusieurs milliers de lignes : personne ne le colle à la main.
 * Le fichier est lu ici, avec les mêmes garde-fous que le reste : taille
 * plafonnée, aucune confiance dans le nom ni dans le type annoncé par le
 * navigateur, lecture en texte brut et rien d'autre.
 */
function import_payload(): string
{
    $pasted = trim((string)($_POST['list'] ?? ''));
    $f = $_FILES['file'] ?? null;
    if (is_array($f) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string)($f['tmp_name'] ?? '');
        $size = (int)($f['size'] ?? 0);
        // is_uploaded_file : la seule garantie que ce chemin vient bien d'un
        // envoi HTTP et pas d'une valeur fabriquée.
        if ($tmp !== '' && is_uploaded_file($tmp) && $size > 0
            && $size <= \Uptimeez\Import\Foreign::MAX_BYTES) {
            $raw = (string)file_get_contents($tmp, false, null, 0, \Uptimeez\Import\Foreign::MAX_BYTES);
            // Un fichier binaire n'a rien à faire ici : on le refuse au lieu de
            // le donner à manger aux analyseurs.
            if ($raw !== '' && !str_contains(substr($raw, 0, 4096), "\0")) {
                return $pasted !== '' ? $pasted . "\n" . $raw : $raw;
            }
        }
    }
    return $pasted;
}

/**
 * Le dernier verdict d'une sonde, dans la langue de qui regarde.
 *
 * Le collecteur enregistre une phrase source et ses variables ; la traduction a
 * lieu ici, à l'affichage. C'est la seule façon qu'un même incident se lise en
 * français pour l'un et en anglais pour l'autre : la langue du cron n'a pas à
 * décider de la langue de l'écran.
 */
function verdict_text(?array $row, int $cut = 0): string
{
    if (!$row) return '';
    $msg = trim((string)($row['last_message'] ?? ''));
    if ($msg === '') return '';
    $out = t($msg, jdec($row['last_message_vars'] ?? null));
    return $cut > 0 ? str_cut($out, $cut) : $out;
}

/** URL interne. */
function u(string $page, array $params = []): string
{
    $params = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Durée lisible : 4210 -> "1 h 10 min". */
function human_duration(?int $sec): string
{
    $sec = max(0, (int)$sec);
    if ($sec < 60)    return $sec . ' s';
    if ($sec < 3600)  return floor($sec / 60) . ' min';
    if ($sec < 86400) {
        $h = floor($sec / 3600); $m = floor(($sec % 3600) / 60);
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }
    $d = floor($sec / 86400); $h = floor(($sec % 86400) / 3600);
    return $d . ' j' . ($h ? ' ' . $h . ' h' : '');
}

/** Écart lisible depuis un timestamp : "il y a 3 min". */
function human_since(?string $datetime): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if (!$ts) return '—';
    $d = time() - $ts;
    if ($d < 10)  return t('à l\'instant');
    if ($d < 60)  return "il y a {$d} s";
    return 'il y a ' . human_duration($d);
}

function human_bytes(?int $b): string
{
    $b = (int)$b;
    if ($b < 1024) return $b . ' o';
    // Le séparateur décimal suit la langue, et la décimale ne s'affiche que si
    // elle apporte quelque chose : « 2 Ko » plutôt que « 2,0 Ko ».
    $show = static function (float $v, int $max): string {
        $r = round($v, $max);
        $dec = fmod($r, 1.0) === 0.0 ? 0 : $max;
        return \Uptimeez\Ui::num($r, $dec);
    };
    if ($b < 1048576) return $show($b / 1024, $b < 10240 ? 1 : 0) . ' Ko';
    // L'échelle monte jusqu'au téraoctet : l'espace disque libre et un an
    // d'historique se comptent en gigaoctets, et « 14 346 Mo » ne se lit pas.
    if ($b < 1073741824)    return $show($b / 1048576, 2) . ' Mo';
    if ($b < 1099511627776) return $show($b / 1073741824, 2) . ' Go';
    return $show($b / 1099511627776, 2) . ' To';
}

/** Normalise une saisie utilisateur en URL http(s). */
function normalize_url(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = preg_replace('~\s+~', '', $raw) ?? $raw;
    if (!preg_match('~^https?://~i', $raw)) $raw = 'https://' . ltrim($raw, '/');
    $parts = parse_url($raw);
    if (!$parts || empty($parts['host'])) return null;
    if (!preg_match('~^[a-z0-9\-._]+$~i', $parts['host'])) return null;
    if (!str_contains($parts['host'], '.')) return null;

    $scheme = strtolower($parts['scheme'] ?? 'https');
    $url    = $scheme . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) $url .= ':' . $parts['port'];
    $url .= $parts['path'] ?? '/';
    if (($parts['path'] ?? '') === '') $url .= '';
    if (!empty($parts['query'])) $url .= '?' . $parts['query'];
    return $url;
}

function host_of(string $url): string
{
    return (string)(parse_url($url, PHP_URL_HOST) ?: $url);
}

/** Domaine enregistrable approximatif (gère les ccTLD à deux niveaux courants). */
function registrable_domain(string $host): string
{
    $host = strtolower(preg_replace('~^www\.~', '', $host) ?? $host);
    // Une adresse IP n'a pas de domaine enregistrable : on la garde telle quelle.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $host;
    $parts = explode('.', $host);
    $n = count($parts);
    if ($n <= 2) return $host;
    $second = ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu'];
    if (strlen($parts[$n - 1]) === 2 && in_array($parts[$n - 2], $second, true) && $n >= 3) {
        return implode('.', array_slice($parts, -3));
    }
    return implode('.', array_slice($parts, -2));
}

/** Résout une URL relative par rapport à une base. */
function resolve_url(string $base, string $rel): ?string
{
    $rel = trim($rel);
    if ($rel === '' || str_starts_with($rel, 'data:') || str_starts_with($rel, 'javascript:')) return null;
    if (preg_match('~^https?://~i', $rel)) return $rel;
    if (str_starts_with($rel, '//')) {
        return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $rel;
    }
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return null;
    $root = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($rel, '/')) return $root . $rel;
    if (str_starts_with($rel, '#') || str_starts_with($rel, '?')) return rtrim($base, '#?') . $rel;

    $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/') ?: '/';
    $path = $dir . $rel;
    // Normalisation des ../
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '.' || $seg === '') { if ($seg === '' && !$segments) $segments[] = ''; continue; }
        if ($seg === '..') { if (count($segments) > 1) array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $root . '/' . ltrim(implode('/', $segments), '/');
}


function now(): string
{
    return date('Y-m-d H:i:s');
}

function jdec(?string $s): array
{
    if (!$s) return [];
    $v = json_decode($s, true);
    return is_array($v) ? $v : [];
}

function jenc(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

/**
 * Réduit un texte à sa forme comparable : minuscules, signes diacritiques retirés.
 *
 * Volontairement indépendant de la langue : « casse » trouve « cassé », « munchen »
 * trouve « München », « joao » trouve « João », « lodz » trouve « Łódź », « viet »
 * trouve « việt ». Les écritures sans diacritiques (arabe, chinois, hébreu) sont
 * seulement mises en minuscules.
 *
 * Ce repli sert exclusivement à comparer : il ne doit jamais servir à afficher.
 */
function fold(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');

    // 1. Décomposition Unicode : la voie propre, quelle que soit la langue.
    if (class_exists('\Normalizer')) {
        $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if ($d !== false) {
            // Retire les marques combinantes (U+0300-U+036F) laissées par la décomposition.
            $s = (string)preg_replace('~\p{Mn}+~u', '', $d);
        }
    }

    // 2. Caractères qui ne se décomposent pas (ligatures, lettres barrées).
    static $special = [
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'ø' => 'o', 'đ' => 'd', 'ð' => 'd',
        'þ' => 'th', 'ł' => 'l', 'ħ' => 'h', 'ŧ' => 't', 'ı' => 'i', 'ĳ' => 'ij',
        'ŀ' => 'l', 'ŉ' => 'n', 'ſ' => 's', 'ẛ' => 's', 'µ' => 'u',
    ];
    $s = strtrr_utf8($s, $special);

    // 3. Repli de secours si l'extension intl est absente : table Latin étendue.
    if (!class_exists('\Normalizer')) {
        static $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
            'ă' => 'a', 'ą' => 'a', 'ǎ' => 'a', 'ȧ' => 'a',
            'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
            'ď' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e',
            'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i',
            'į' => 'i', 'ǐ' => 'i',
            'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
            'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ŏ' => 'o',
            'ő' => 'o', 'ǒ' => 'o',
            'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
            'ţ' => 't', 'ť' => 't',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
            'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ǔ' => 'u',
            'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        ];
        $s = strtrr_utf8($s, $map);
    }
    return $s;
}

/** strtr sur des chaînes UTF-8 multi-octets (strtr classique casse les accents). */
function strtrr_utf8(string $s, array $map): string
{
    // str_replace travaille au niveau octet, ce qui convient ici car les clés sont
    // des séquences UTF-8 complètes et non des octets isolés.
    return str_replace(array_keys($map), array_values($map), $s);
}

/**
 * Neutralise une cellule de tableur avant export.
 *
 * Un nom de sonde ou un message d'erreur commençant par « = », « + », « - »,
 * « @ » ou une tabulation est interprété comme une formule par Excel et
 * LibreOffice : c'est-à-dire comme du code, chez le client à qui on envoie le
 * fichier. On préfixe d'une apostrophe, qui force le mode texte et n'apparaît
 * pas à l'écran.
 */
function csv_cell(mixed $v): string
{
    $s = (string)$v;
    if ($s !== '' && str_contains("=+-@\t\r", $s[0])) return "'" . $s;
    return $s;
}

/** Coupe un texte proprement. */
function str_cut(string $s, int $len = 120): string
{
    $s = trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}
