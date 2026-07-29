<?php
/**
 * Uptimer : recherche de code mort.
 *
 * Cinq familles, cinq questions :
 *   1. quelles méthodes publiques ne sont appelées nulle part ?
 *   2. quelles fonctions globales ne servent plus ?
 *   3. quelles classes ne sont jamais instanciées ni référencées ?
 *   4. quelles classes CSS n'apparaissent dans aucun gabarit ?
 *   5. quels msgid ne sont plus demandés par le code ?
 *
 * L'analyse est volontairement conservatrice : une occurrence textuelle suffit
 * à considérer un symbole comme utilisé. Un faux positif est donc plus probable
 * qu'un faux négatif : c'est le bon sens du compromis pour un outil de revue.
 *
 *   php bin/deadcode.php            # rapport complet
 *   php bin/deadcode.php --strict   # code de sortie 1 s'il reste du mort
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$ROOT   = dirname(__DIR__);
$STRICT = in_array('--strict', $argv, true);
$issues = 0;

function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 58 - mb_strlen($s))) . "\n"; }

/** Tous les fichiers d'un type, hors données et captures. */
function files(string $root, array $exts): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (str_contains($p, '/data/') || str_contains($p, '/.git/') || str_contains($p, '/docs/img/')) continue;
        foreach ($exts as $e) if (str_ends_with($p, $e)) { $out[] = $p; break; }
    }
    sort($out);
    return $out;
}

$php  = files($ROOT, ['.php']);
$front = files($ROOT, ['.php', '.js', '.mjs']);
$srcAll = '';
foreach ($php as $f) $srcAll .= file_get_contents($f) . "\n";

// =========================================================================
title('Méthodes publiques jamais appelées');
// =========================================================================
$dead = [];
foreach ($php as $f) {
    if (str_contains($f, '/bin/')) continue;          // les scripts sont des points d'entrée
    $src = (string)file_get_contents($f);
    if (!preg_match('~^\s*(?:final\s+)?class\s+(\w+)~m', $src, $cm)) continue;
    $class = $cm[1];
    if (!preg_match_all('~public\s+(?:static\s+)?function\s+(\w+)\s*\(~', $src, $m)) continue;
    foreach ($m[1] as $method) {
        if (str_starts_with($method, '__')) continue;  // constructeurs, magie
        // Appelée quelque part ? On accepte toute forme d'appel.
        $uses = 0;
        foreach (['::' . $method . '(', '->' . $method . '(', "'" . $method . "'", '"' . $method . '"'] as $needle) {
            $uses += substr_count($srcAll, $needle);
        }
        // La déclaration elle-même ne compte pas.
        $declared = preg_match_all('~function\s+' . preg_quote($method, '~') . '\s*\(~', $srcAll);
        if ($uses === 0) $dead[] = "$class::$method()   " . basename($f) . "  (déclarée ×$declared)";
    }
}
if ($dead) { $issues += count($dead); foreach ($dead as $d) echo "  MORT  $d\n"; }
else echo "  aucune\n";

// =========================================================================
title('Fonctions globales jamais appelées');
// =========================================================================
$deadFn = [];
if (preg_match_all('~^function\s+(\w+)\s*\(~m', (string)file_get_contents($ROOT . '/src/helpers.php'), $m)) {
    foreach ($m[1] as $fn) {
        // Un appel = le nom suivi d'une parenthèse, hors sa propre déclaration.
        $calls = preg_match_all('~(?<![\w:>$])' . preg_quote($fn, '~') . '\s*\(~', $srcAll);
        $decl  = preg_match_all('~function\s+' . preg_quote($fn, '~') . '\s*\(~', $srcAll);
        if ($calls - $decl <= 0) $deadFn[] = "$fn()   appels " . ($calls - $decl);
    }
}
if ($deadFn) { $issues += count($deadFn); foreach ($deadFn as $d) echo "  MORT  $d\n"; }
else echo "  aucune\n";

// =========================================================================
title('Classes jamais référencées');
// =========================================================================
$deadCls = [];
foreach ($php as $f) {
    if (str_contains($f, '/bin/')) continue;
    $src = (string)file_get_contents($f);
    if (!preg_match('~^\s*(?:final\s+)?class\s+(\w+)~m', $src, $cm)) continue;
    $class = $cm[1];
    $uses = substr_count($srcAll, $class . '::') + substr_count($srcAll, 'new ' . $class)
          + substr_count($srcAll, '\\' . $class) + substr_count($srcAll, 'use Uptimer\\' . $class);
    if ($uses === 0) $deadCls[] = "$class   " . basename($f);
}
if ($deadCls) { $issues += count($deadCls); foreach ($deadCls as $d) echo "  MORT  $d\n"; }
else echo "  aucune\n";

// =========================================================================
title('Variables CSS employées sans être définies');
// =========================================================================
// Une var() non définie et sans valeur de repli rend la déclaration invalide :
// la bordure prend la couleur du texte au lieu du gris prévu, et personne ne
// s'en aperçoit avant de regarder l'écran de près. C'est arrivé sur --line,
// utilisée six fois et déclarée nulle part.
$cssVars = (string)file_get_contents($ROOT . '/assets/app.css');
$usedVars = [];
if (preg_match_all('~var\(\s*(--[a-z0-9-]+)\s*\)~i', $cssVars, $m)) {
    foreach ($m[1] as $v) $usedVars[$v] = true;
}
$defVars = [];
if (preg_match_all('~(?<!var\()(--[a-z0-9-]+)\s*:~i', $cssVars, $m)) {
    foreach ($m[1] as $v) $defVars[$v] = true;
}
$ghost = array_keys(array_diff_key($usedVars, $defVars));
if ($ghost) {
    $issues += count($ghost);
    foreach ($ghost as $v) {
        $n = preg_match_all('~var\(\s*' . preg_quote($v, '~') . '\s*\)~i', $cssVars);
        echo "  MORT  $v : employée $n fois, définie nulle part\n";
    }
} else echo "  aucune\n";

// =========================================================================
title('Classes CSS déclarées mais jamais utilisées');
// =========================================================================
$css = (string)file_get_contents($ROOT . '/assets/app.css');
// On retire les blocs de déclaration : seuls les sélecteurs nous intéressent.
$selectors = [];
if (preg_match_all('~\.([a-z][a-z0-9_-]{1,40})~i', $css, $m)) {
    foreach ($m[1] as $c) $selectors[$c] = true;
}
$markup = '';
foreach ($front as $f) {
    if (str_ends_with($f, 'app.css')) continue;
    $markup .= file_get_contents($f) . "\n";
}
$unused = [];
foreach (array_keys($selectors) as $c) {
    // Une classe peut être écrite en PHP ('badge-' . $tone) : on accepte aussi
    // les préfixes, sinon toute classe construite dynamiquement paraîtrait morte.
    if (str_contains($markup, $c)) continue;
    // Une classe construite par concaténation : 'dot dot-' . $state : n'apparaît
    // jamais en entier dans le source : on accepte son préfixe.
    $prefix = preg_replace('~-[a-z0-9]+$~', '-', $c);
    if ($prefix !== $c && str_contains($markup, $prefix)) continue;
    $unused[] = $c;
}
if ($unused) {
    $issues += count($unused);
    echo '  ' . count($unused) . " classe(s) sans emploi :\n";
    foreach (array_chunk($unused, 6) as $row) echo '      .' . implode('  .', $row) . "\n";
} else echo "  aucune\n";

// =========================================================================
title('msgid des catalogues absents du code');
// =========================================================================
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/bin/i18n-audit.php') . ' --json 2>/dev/null');
$ids = json_decode((string)$out, true)['msgids'] ?? [];
$known = array_fill_keys($ids, true);
$orphans = [];
foreach (glob($ROOT . '/lang/*.php') ?: [] as $cat) {
    if (str_contains($cat, '_dynamiques')) continue;
    $table = require $cat;
    if (!is_array($table)) continue;
    $bad = array_diff(array_keys($table), array_keys($known));
    if ($bad) $orphans[basename($cat)] = $bad;
}
if ($orphans) {
    foreach ($orphans as $file => $bad) {
        $issues += count($bad);
        echo '  ' . $file . ' : ' . count($bad) . " clé(s) orpheline(s)\n";
        foreach (array_slice($bad, 0, 5) as $b) echo '      « ' . mb_substr($b, 0, 60) . " »\n";
    }
} else echo "  aucune\n";

// =========================================================================
title('Fichiers jamais inclus ni servis');
// =========================================================================
$entry = ['index.php', 'api.php', 'cron.php', 'beat.php', 'install.php'];
$orphanFiles = [];
foreach ($php as $f) {
    $base = basename($f);
    if (in_array($base, $entry, true) || str_contains($f, '/bin/')) continue;
    if (str_contains($f, '/lang/')) continue;
    $stem = preg_replace('~\.php$~', '', $base);
    // Une vue est chargée par son nom de page, une classe par son nom court.
    $hit = substr_count($srcAll, $base) + substr_count($srcAll, "'" . $stem . "'")
         + substr_count($srcAll, $stem . '::') + substr_count($srcAll, 'new ' . ucfirst($stem));
    if ($hit === 0) $orphanFiles[] = $f;
}
if ($orphanFiles) { $issues += count($orphanFiles); foreach ($orphanFiles as $f) echo '  ORPHELIN  ' . str_replace($ROOT . '/', '', $f) . "\n"; }
else echo "  aucun\n";

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
echo $issues === 0
    ? "✅ Aucun code mort détecté.\n"
    : "⚠️  $issues point(s) à examiner (certains peuvent être de faux positifs).\n";
exit($STRICT && $issues > 0 ? 1 : 0);
