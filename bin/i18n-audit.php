<?php
/**
 * Uptimer : audit de traduction.
 *
 * Trois questions, trois réponses :
 *   1. quelles phrases le code demande-t-il à traduire ?      (msgid extraits)
 *   2. lesquelles ressemblent à un fragment inutilisable ?    (à réparer)
 *   3. quelles phrases visibles ne sont pas encore traduites ? (littéraux nus)
 *
 * Usage : php bin/i18n-audit.php [--fragments] [--nus] [--manquants=xx] [--json]
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Uptimer\I18n;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('~^--([a-z-]+)(?:=(.*))?$~', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$all = !array_intersect(array_keys($opts), ['fragments', 'nus', 'manquants', 'json']);

/** Fichiers qui produisent de l'interface. */
function ui_files(): array
{
    $out = [];
    foreach ([
        UPTIMER_ROOT . '/views',
        UPTIMER_ROOT . '/views/partials',
        UPTIMER_ROOT . '/src',
        UPTIMER_ROOT . '/src/Check',
        UPTIMER_ROOT . '/src/Detect',
        UPTIMER_ROOT . '/src/Notify',
    ] as $dir) {
        foreach (glob($dir . '/*.php') ?: [] as $f) $out[] = $f;
    }
    foreach (['index.php', 'api.php', 'install.php', 'beat.php'] as $f) {
        $out[] = UPTIMER_ROOT . '/' . $f;
    }
    return array_values(array_filter($out, 'is_file'));
}

/** Extrait les msgid de tous les appels t()/te()/tn()/tne()/hint(). */
function extract_msgids(array $files): array
{
    $ids = [];
    foreach ($files as $f) {
        $src = (string)file_get_contents($f);
        // Un appel peut porter plusieurs msgid : tn($n, 'un', '{n} deux').
        // On avance caractère par caractère depuis l'ouverture de l'appel,
        // jusqu'à la parenthèse fermante ou au tableau de substitution : un
        // motif d'expression régulière ne sait pas suivre l'imbrication.
        $lits = [];
        $len = strlen($src);
        // Le mot doit être isolé : « complète (… » n'est pas un appel à te().
        // \b ne suffit pas, un octet accentué compte comme frontière de mot.
        $call = '~(?<![\w\x80-\xFF$>\-])(?:te|t|tne|tn|hint)\s*\(~';
        if (preg_match_all($call, $src, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$open, $at]) {
                $i = $at + strlen($open);
                $depth = 1; $bracket = 0;
                while ($i < $len && $depth > 0) {
                    $ch = $src[$i];
                    if ($ch === '(') { $depth++; $i++; continue; }
                    if ($ch === ')') { $depth--; $i++; continue; }
                    // Tout ce qui est entre crochets est du code, jamais un
                    // msgid : indice de tableau $m['url'], variables de
                    // substitution ['host' => …].
                    if ($ch === '[') { $bracket++; $i++; continue; }
                    if ($ch === ']') { $bracket--; $i++; continue; }
                    if ($bracket > 0) { 
                        if ($ch === "'" || $ch === '"') {
                            $q = $ch; $j = $i + 1;
                            while ($j < $len) {
                                if ($src[$j] === '\\') { $j += 2; continue; }
                                if ($src[$j] === $q) break;
                                $j++;
                            }
                            $i = $j + 1;
                            continue;
                        }
                        $i++;
                        continue;
                    }
                    if ($ch === "'" || $ch === '"') {
                        $q = $ch; $j = $i + 1;
                        while ($j < $len) {
                            if ($src[$j] === '\\') { $j += 2; continue; }
                            if ($src[$j] === $q) break;
                            $j++;
                        }
                        $lits[] = substr($src, $i, $j - $i + 1);
                        $i = $j + 1;
                        continue;
                    }
                    $i++;
                }
            }
        }
        foreach ($lits as $lit) {
            {
                $val = $lit[0] === "'"
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($lit, 1, -1))
                    : str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], substr($lit, 1, -1));
                if ($val === '') continue;
                $ids[$val][] = basename($f);
            }
        }
    }
    // Les msgid passés par variable (libellés d'état, verdicts stockés en base)
    // ne peuvent pas être trouvés par lecture du code : ils sont déclarés.
    $extra = UPTIMER_ROOT . '/lang/_dynamiques.php';
    if (is_file($extra)) {
        foreach ((array)require $extra as $id) {
            if (is_string($id) && $id !== '') $ids[$id][] = '_dynamiques.php';
        }
    }
    ksort($ids);
    return $ids;
}

/** Un msgid intraduisible tel quel : morceau de phrase, mot isolé, saut de ligne. */
function is_fragment(string $id): bool
{
    $s = trim($id);
    if ($s === '') return true;
    if (str_contains($s, "\n")) return true;
    // Commence par un séparateur, ou finit par un séparateur d'énumération :
    // la phrase a été coupée. Les deux-points et point-virgules finaux sont
    // légitimes (étiquette de champ, élément de liste).
    if (preg_match('~^[·—–,;:/|)]~u', $s)) return true;
    if (preg_match('~[·—–,/|(]$~u', $s)) return true;
    // Un mot isolé n'est pas un défaut en soi : « critique », « thème » ou
    // « activée » sont des étiquettes de badge, et chaque langue les traduit.
    // Ce qui n'a rien à faire dans un catalogue, c'est une unité ou une
    // abréviation technique : elle s'écrit pareil partout et elle signale
    // presque toujours une phrase coupée autour d'une valeur.
    if (preg_match('~^[a-zà-ÿ]+$~u', $s)) {
        static $ok    = ['uptime', 'sonde', 'sondes', 'ping', 'jours', 'oui', 'non'];
        static $units = ['ms', 'ko', 'mo', 'go', 'px', 'req', 'min', 'sec', 'api', 'url',
                         'css', 'js', 'html', 'ip', 'dns', 'tls', 'ssl', 'http', 'https'];
        if (in_array($s, $units, true)) return true;
        if (mb_strlen($s) <= 3 && !in_array($s, $ok, true)) return true;
    }
    // Se termine par une préposition : la valeur suit, donc msgid coupé.
    // On ne l'applique qu'aux phrases courtes : un paragraphe qui finit ainsi
    // est presque toujours tronqué à l'affichage, pas dans le source.
    if (mb_strlen($s) <= 34 && preg_match('~\b(de|du|des|le|la|les|à|au|aux|en|sur|pour|par|dans|et|ou|toutes|tous)$~u', $s)) return true;
    return false;
}

/** Cherche les littéraux français encore hors de t(). */
function bare_strings(array $files): array
{
    $out = [];
    foreach ($files as $f) {
        $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            // On ignore les commentaires et les lignes déjà traduites en entier.
            $trim = ltrim($line);
            if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*')) continue;

            $hits = [];
            // Un appel de traduction couvre tout ce qui le suit sur la ligne :
            // les msgid d'un tn() sont en 2e et 3e position, pas seulement en 1re.
            $cut = null;
            if (preg_match('~\b(?:te|t|tne|tn|hint)\s*\(~', $line, $mm, PREG_OFFSET_CAPTURE)) {
                $cut = (int)$mm[0][1];
            }
            // Littéraux PHP contenant du français, échappements compris.
            if (preg_match_all('~(?<![\w$])(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")~', $line, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$lit, $off]) {
                    if ($cut !== null && $off > $cut) continue;
                    $val = str_replace(["\\'", '\\"'], ["'", '"'], substr($lit, 1, -1));
                    if (!looks_french($val)) continue;
                    $hits[] = $val;
                }
            }
            // Texte HTML nu.
            if (preg_match_all('~>([^<>?]{3,}?)<~', $line, $m)) {
                foreach ($m[1] as $txt) {
                    if (looks_french($txt) && !str_contains($txt, '?=')) $hits[] = trim($txt);
                }
            }
            // Attributs visibles restés en clair.
            if (preg_match_all('~\b(?:title|placeholder|aria-label|alt)="([^"<>]{3,})"~', $line, $m)) {
                foreach ($m[1] as $txt) {
                    if (looks_french($txt) && !str_contains($txt, '?=')) $hits[] = trim($txt);
                }
            }
            foreach (array_unique($hits) as $h) {
                $out[] = [basename($f), $i + 1, $h];
            }
        }
    }
    return $out;
}

function looks_french(string $s): bool
{
    $s = trim($s);
    if (mb_strlen($s) < 3) return false;
    if (preg_match('~^[\w./:#?&=%-]+$~', $s)) return false;      // identifiant, URL, classe CSS
    if (preg_match('~^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|FROM|WHERE)\b~i', $s)) return false;
    if (str_contains($s, '<path') || str_contains($s, 'viewBox')) return false;
    if (preg_match('~[éèêëàâçùûôîïœÀÉÈÊ]~u', $s)) return true;
    return (bool)preg_match('~(?<![\w])(le|la|les|des|du|une|est|pas|sur|pour|avec|dans|aucun|aucune|vous|votre|cette|tous|toutes|plus|jamais|encore)(?![\w])~u', $s);
}

// ---------------------------------------------------------------------------
$files   = ui_files();
$msgids  = extract_msgids($files);
$frags   = array_filter(array_keys($msgids), 'is_fragment');
$bare    = bare_strings($files);

$sep = str_repeat('─', 68);
if ($all || isset($opts['fragments'])) {
    echo "\n$sep\n" . count($frags) . " fragment(s) à réparer (msgid intraduisible)\n$sep\n";
    foreach ($frags as $id) {
        printf("  %-46s %s\n", '«' . str_replace("\n", '⏎', mb_substr($id, 0, 44)) . '»',
               implode(' ', array_unique($msgids[$id])));
    }
}
if ($all || isset($opts['nus'])) {
    echo "\n$sep\n" . count($bare) . " littéral/littéraux encore hors traduction\n$sep\n";
    $byFile = [];
    foreach ($bare as [$f, $l, $s]) $byFile[$f][] = "$l: $s";
    ksort($byFile);
    foreach ($byFile as $f => $rows) {
        echo "  $f (" . count($rows) . ")\n";
        foreach (array_slice($rows, 0, ($opts['nus'] ?? true) === true ? 8 : 200) as $r) echo "      $r\n";
    }
}
if (isset($opts['manquants'])) {
    $lang = is_string($opts['manquants']) ? $opts['manquants'] : 'en';
    $cat  = I18n::catalogue($lang);
    $miss = array_values(array_diff(array_keys($msgids), array_keys($cat)));
    echo "\n$sep\n" . count($miss) . " msgid absent(s) du catalogue « $lang » sur " . count($msgids) . "\n$sep\n";
    foreach ($miss as $id) echo '  ' . str_replace("\n", '⏎', $id) . "\n";
}
if (isset($opts['json'])) {
    echo json_encode(['msgids' => array_keys($msgids), 'fragments' => array_values($frags)],
                     JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if ($all) {
    echo "\n  " . count($msgids) . " msgid distinct(s) · " . count($frags) . " à réparer · "
       . count($bare) . " littéral/littéraux nus\n\n";
}
