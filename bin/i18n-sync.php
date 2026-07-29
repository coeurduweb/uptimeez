<?php
/**
 * Uptimeez, synchronisation des catalogues de traduction.
 *
 * Les catalogues du dépôt sont la source de vérité. Ce script les remet en
 * phase avec le code après une modification de texte :
 *
 *   1. il relit les msgid réellement demandés par le code ;
 *   2. il conserve chaque traduction existante ;
 *   3. quand une phrase source a été reformulée (ponctuation, virgule au lieu
 *      d'un tiret), il retrouve l'ancienne clé par comparaison normalisée et
 *      reporte la traduction sur la nouvelle ;
 *   4. il réécrit les catalogues dans l'ordre des msgid, et liste ce qui reste
 *      à traduire.
 *
 * Sans lui, chaque reformulation d'une phrase française périmait neuf
 * catalogues d'un coup. C'est arrivé, d'où ce script.
 *
 *   php bin/i18n-sync.php              # synchronise et rend compte
 *   php bin/i18n-sync.php --patch=f.json  # ajoute des traductions depuis un JSON
 *                                         # { "en": {"msgid": "translation"}, … }
 *   php bin/i18n-sync.php --dry         # ne réécrit rien
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimeez\I18n;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$ROOT  = dirname(__DIR__);
$DRY   = in_array('--dry', $argv, true);
$patch = [];
foreach ($argv as $a) {
    if (preg_match('~^--patch=(.+)$~', $a, $m)) {
        $patch = json_decode((string)file_get_contents($m[1]), true) ?: [];
    }
}

/** Forme comparable d'une phrase : la ponctuation de liaison ne compte pas. */
function msg_norm(string $k): string
{
    $k = str_replace(['—', '–'], ' ', $k);
    return trim(strtolower((string)preg_replace('~[\s:,.;]+~u', ' ', $k)));
}

// ---- msgid réellement demandés par le code ------------------------------
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' '
     . escapeshellarg($ROOT . '/bin/i18n-audit.php') . ' --json 2>/dev/null');
$ids = json_decode((string)$out, true)['msgids'] ?? [];
if (!$ids) exit("Impossible de lire les msgid : bin/i18n-audit.php a échoué.\n");
echo count($ids) . " msgid demandés par le code\n";

$report = [];
foreach (array_keys(I18n::LANGS) as $lang) {
    if ($lang === I18n::SOURCE) continue;          // le français est la source

    $cat = I18n::catalogue($lang);
    $byNorm = [];
    foreach ($cat as $k => $v) $byNorm[msg_norm($k)] = $v;
    foreach (($patch[$lang] ?? []) as $k => $v) {
        $cat[$k] = $v;
        $byNorm[msg_norm($k)] = $v;
    }

    $new = [];
    $carried = 0;
    foreach ($ids as $id) {
        if (isset($cat[$id]) && $cat[$id] !== '') {
            $new[$id] = $cat[$id];
        } elseif (isset($byNorm[msg_norm($id)])) {
            // La phrase source a été reformulée : la traduction suit.
            $new[$id] = $byNorm[msg_norm($id)];
            $carried++;
        }
    }
    $missing = count($ids) - count($new);
    $dropped = count($cat) - count(array_intersect_key($cat, $new));

    $report[$lang] = ['have' => count($new), 'missing' => $missing,
                      'carried' => $carried, 'dropped' => max(0, $dropped)];

    if ($DRY) continue;

    $title = I18n::LANGS[$lang][0] . ' catalogue'
           . (I18n::dir($lang) === 'rtl' ? ' (right-to-left)' : '')
           . ($lang === I18n::DEFAULT ? ' (default language)' : '');
    $php = ["<?php", "/**", " * Uptimeez, " . $title . ".", " *",
            " * Keys are the French source sentences (msgid), the way gettext does it.",
            " * The product name never appears in a key: sentences write {app}, which",
            " * I18n substitutes, so renaming the product never invalidates a catalogue.",
            " * A missing key falls back to English, then to the source text.",
            " *",
            " * Regenerate with: php bin/i18n-sync.php",
            " * What is left:    php bin/i18n-audit.php --manquants=" . $lang,
            " */", "declare(strict_types=1);", "", "return ["];
    foreach ($new as $k => $v) {
        $php[] = '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ',';
    }
    $php[] = '];';
    file_put_contents($ROOT . '/lang/' . $lang . '.php', implode("\n", $php) . "\n");
}

printf("\n%-6s %8s %8s %9s %8s\n", 'langue', 'traduit', 'manquant', 'reporté', 'retiré');
echo str_repeat('─', 46) . "\n";
foreach ($report as $lang => $r) {
    printf("%-6s %8d %8d %9d %8d\n", $lang, $r['have'], $r['missing'], $r['carried'], $r['dropped']);
}
$enMissing = $report[I18n::DEFAULT]['missing'] ?? 0;
echo "\n" . ($enMissing === 0
    ? "Le catalogue anglais est complet : aucune phrase ne retombera sur le français.\n"
    : "⚠️  $enMissing phrase(s) manquent en anglais : "
      . "php bin/i18n-audit.php --manquants=en\n");
exit($enMissing === 0 ? 0 : 1);
