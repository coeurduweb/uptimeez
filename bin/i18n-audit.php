<?php
/**
 * Uptimeez : audit de traduction.
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

use Uptimeez\I18n;

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
        UPTIMEEZ_ROOT . '/views',
        UPTIMEEZ_ROOT . '/views/partials',
        UPTIMEEZ_ROOT . '/src',
        UPTIMEEZ_ROOT . '/src/Check',
        UPTIMEEZ_ROOT . '/src/Detect',
        UPTIMEEZ_ROOT . '/src/Notify',
    ] as $dir) {
        foreach (glob($dir . '/*.php') ?: [] as $f) $out[] = $f;
    }
    foreach (['index.php', 'api.php', 'install.php', 'beat.php'] as $f) {
        $out[] = UPTIMEEZ_ROOT . '/' . $f;
    }
    return array_values(array_filter($out, 'is_file'));
}

/** Extrait les msgid de tous les appels t()/te()/tn()/tne()/hint()/Fail::tr(). */
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
        // « tr » est le traducteur de Fail : même rôle que t(), mais incapable
        // d'échouer, parce qu'il sert à afficher une panne. Ses phrases
        // appartiennent aux catalogues comme les autres.
        $call = '~(?:\bI18n::[tn]|(?<![\w\x80-\xFF$>\-])(?:te|tne|tn|hint|tr|t))\s*\(~';
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
    $extra = UPTIMEEZ_ROOT . '/lang/_dynamiques.php';
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
        static $ok    = ['uptime', 'sonde', 'sondes', 'ping', 'jours', 'oui', 'non', 'mai'];
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
/**
 * Littéraux français encore hors traduction.
 *
 * @param array $known msgid déjà connus : un littéral qui EST un msgid est
 *                     traduit quelque part, souvent à l'affichage après un
 *                     passage en base. Le signaler serait crier au loup.
 */
function bare_strings(array $files, array $known = []): array
{
    // Deux fichiers ne produisent jamais de texte d'interface : l'un écrit le
    // fichier de configuration, l'autre s'exécute avant que la traduction
    // existe. Les analyser ne dirait rien d'utile.
    $skipFiles = ['Config.php', 'bootstrap.php', 'I18n.php'];
    $out = [];
    foreach ($files as $f) {
        if (in_array(basename($f), $skipFiles, true)) continue;
        $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            // On ignore les commentaires et les lignes déjà traduites en entier.
            $trim = ltrim($line);
            if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*')) continue;

            $hits = [];
            // Un appel de traduction couvre tout ce qui le suit sur la ligne :
            // les msgid d'un tn() sont en 2e et 3e position, pas seulement en 1re.
            $cut = null;
            if (preg_match('~(?:\bI18n::[tn]|\b(?:te|t|tne|tn|hint))\s*\(~', $line, $mm, PREG_OFFSET_CAPTURE)) {
                $cut = (int)$mm[0][1];
            }
            // Littéraux PHP contenant du français, échappements compris.
            if (preg_match_all('~(?<![\w$])(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")~', $line, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$lit, $off]) {
                    if ($cut !== null && $off > $cut) continue;
                    $val = str_replace(["\\'", '\\"'], ["'", '"'], substr($lit, 1, -1));
                    // Dans un gabarit, les guillemets d'un attribut HTML
                    // ressemblent à un littéral PHP quand l'attribut contient
                    // une balise d'échappement PHP. Ce n'est pas du texte en
                    // dur, c'est du balisage autour d'un texte déjà traduit.
                    // (Le commentaire évite d'écrire la balise fermante : elle
                    // interromprait ce fichier PHP au beau milieu.)
                    if (str_contains($val, '<?') || str_contains($val, '?>')
                        || preg_match('~\b(?:te|t|tn|tne|hint)\s*\(~', $val)) continue;
                    // Une clé de tableau n'est jamais affichée : c'est un motif à
                    // reconnaître (signature de panne, en-tête HTTP), pas une phrase.
                    if (preg_match('~^\s*=>~', substr($line, $off + strlen($lit)))) continue;
                    // Une expression régulière contient du français sans être du
                    // texte : elle sert justement à reconnaître ce français.
                    if (preg_match('{^~[\^(]|~[imsuxADSUXJ]*$}', $val)) continue;
                    // Un motif assemblé sur plusieurs lignes : la variable qui le
                    // reçoit le dit (« $generic = '~^(… », puis la suite).
                    if (preg_match('~^\s*\$(?:re|regex|pattern|generic|motif)\b~', $line)
                        || preg_match('~^\s*\.\s*\x27~', $line) && str_contains($val, '|')) continue;
                    if (!looks_french($val)) continue;
                    $hits[] = $val;
                }
            }
            // Un morceau qui contient déjà un appel de traduction est du
            // balisage autour d'un texte traduit, pas du texte en dur.
            $wrapped = static fn(string $txt): bool => str_contains($txt, '?=')
                || preg_match('~\b(?:te|t|tn|tne|hint)\s*\(~', $txt) === 1;
            // Texte HTML nu.
            if (preg_match_all('~>([^<>?]{3,}?)<~', $line, $m)) {
                foreach ($m[1] as $txt) {
                    if (looks_french($txt) && !$wrapped($txt)) $hits[] = trim($txt);
                }
            }
            // Attributs visibles restés en clair.
            if (preg_match_all('~\b(?:title|placeholder|aria-label|alt)="([^"<>]{3,})"~', $line, $m)) {
                foreach ($m[1] as $txt) {
                    if (looks_french($txt) && !$wrapped($txt)) $hits[] = trim($txt);
                }
            }
            foreach (array_unique($hits) as $h) {
                if (isset($known[trim($h)])) continue;
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
    // Balisage, déclaration CSS, en-tête HTTP : trois formes qui contiennent des
    // mots mais ne sont pas des phrases. L'en-tête se reconnaît à son nom collé
    // aux deux-points ; le français, lui, met une espace avant (« Erreur : … »).
    if (str_contains($s, '<') || str_contains($s, '>')) return false;
    if (str_contains($s, ';') && str_contains($s, ':')) return false;
    if (preg_match('~^[A-Z][A-Za-z-]*:\s~', $s)) return false;
    if (preg_match('~[éèêëàâçùûôîïœÀÉÈÊ]~u', $s)) return true;

    // Le français sans accent existait et passait à travers : « Connexion TLS
    // impossible » n'était jamais signalé, donc jamais traduit, donc affiché en
    // français dans une interface anglaise. La liste de mots-outils ne suffisait
    // pas ; les adjectifs de verdict comptent autant, puisque c'est justement de
    // ça que les messages du collecteur sont faits.
    //
    // Ce qui reste volontairement hors liste : les messages de console
    // reconstitués (Css.php les reproduit tels que le navigateur les écrit, en
    // anglais, et les traduire serait un contresens), les noms d'extensions,
    // les en-têtes HTTP et les fragments SQL.
    $outils = 'le|la|les|des|du|de|une|est|sont|pas|non|sur|pour|avec|dans|en|sans|par|sous|entre'
            . '|aucun|aucune|vous|votre|cette|chaque|tous|toutes|plus|moins|jamais|encore|trop'
            . '|doit|faut|veuillez|alors|donc|mais|puis';
    $verdicts = 'impossible|invalide|introuvable|inconnu|inconnue|absent|absente|inattendu|inattendue'
              . '|manquant|manquante|refuse|refuse|vide|valide';
    return (bool)preg_match('~(?<![\w])(' . $outils . '|' . $verdicts . ')(?![\w])~u', $s);
}

// ---------------------------------------------------------------------------
$files   = ui_files();
$msgids  = extract_msgids($files);
$frags   = array_filter(array_keys($msgids), 'is_fragment');
// Les msgid déclarés (dont lang/_dynamiques.php) ne sont pas « hors traduction ».
$bare    = bare_strings($files, $msgids);

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
    // Le JSON sert à bin/i18n-sync.php et aux tests : on y met les compteurs,
    // pas seulement les listes, pour qu'un contrôle tienne en une ligne.
    echo json_encode([
        'msgids'    => array_keys($msgids),
        'fragments' => array_values($frags),
        'bare'      => count($bare),
        'bare_list' => array_map(fn(array $b): array => ['file' => $b[0], 'line' => $b[1], 'text' => $b[2]],
                                 array_slice($bare, 0, 50)),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if ($all) {
    echo "\n  " . count($msgids) . " msgid distinct(s) · " . count($frags) . " à réparer · "
       . count($bare) . " littéral/littéraux nus\n\n";
}
