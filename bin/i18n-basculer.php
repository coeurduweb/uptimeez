#!/usr/bin/env php
<?php
/**
 * Switch the SOURCE LANGUAGE of this repository from French to English, in one pass.
 *
 *   php bin/i18n-basculer.php            show what would change, write nothing
 *   php bin/i18n-basculer.php --ecrire   do it
 *
 * ------------------------------------------------------------------------------
 * WHY THIS EXISTS, AND WHY IT IS ONE PASS AND NEVER FILE BY FILE
 * ------------------------------------------------------------------------------
 *
 * This is a public MIT repository whose commits, comments and content are English. The msgids
 * were not: `t('Le domaine expire')` with `lang/en.php` mapping *French string → English string*.
 * The source language WAS French, so every contributor read French inside the code of an English
 * project. That is a barrier at the door of a project that asks for contributions.
 *
 * Migrating one file at a time would break the ten catalogues progressively and leave the product
 * half-switched, which is worse than either end state: half the screens would fall back to their
 * key while the other half translate. So this runs once, over everything, or not at all.
 *
 * ------------------------------------------------------------------------------
 * THE MAP ALREADY EXISTS, AND THAT IS WHAT MAKES THIS CHEAP
 * ------------------------------------------------------------------------------
 *
 * `lang/en.php` is a complete French → English map: 1 696 entries, no gaps, and zero mismatched
 * `{token}` sets (measured before writing this). The migration is therefore mechanical: rewrite
 * the call sites through that map, invert it into `lang/fr.php`, and re-key the nine partial
 * catalogues whose keys are French.
 *
 * ------------------------------------------------------------------------------
 * IT REWRITES BY TOKENISING, NOT BY REGULAR EXPRESSION
 * ------------------------------------------------------------------------------
 *
 * 289 of the 1 696 msgids contain an apostrophe, which a single-quoted PHP literal escapes as
 * `\'`. A textual search-and-replace has to guess the escaping of both sides and gets it wrong
 * silently: it either misses the call site or produces a file that no longer parses. Worse, a
 * short msgid can be a substring of a longer one.
 *
 * `token_get_all()` hands over the exact literals the parser sees. We look for the sequence
 * `t` `(` `<string>`, decode that literal, map it, and re-emit it properly escaped. Nothing is
 * guessed, and a file whose tokens do not round-trip is refused rather than written.
 *
 * ------------------------------------------------------------------------------
 * THE COLLISIONS, AND THE ONE THAT NEEDED A DECISION
 * ------------------------------------------------------------------------------
 *
 * 23 English strings are the target of two different French msgids. They are all French-specific
 * duplications, which is itself the argument for this migration: case variants (« Base de
 * données » / « base de données » → *database*) and gender agreement (« chargées » /
 * « chargés », « présent » / « présente » → *loaded*, *present*). English has neither, so the
 * duplication disappears.
 *
 * Merging is harmless when at most one side is actually called, which is the case for 22 of the
 * 23. ONE pair is genuinely used on both sides and would produce a French agreement error:
 *
 *   - `t('envoyé(s)')` counts SMS deliveries: « 3/5 envoyé(s) » ;
 *   - `t('envoyée')` badges one notification row: « envoyée ».
 *
 * Both mapped to *sent*. Merging them would print « 3/5 envoyée » or badge « envoyé(s) ». So the
 * SMS counter takes a different English word that is just as natural in a count, *delivered*, and
 * French keeps both forms. The override is declared below with this reason, because a reader who
 * finds it later will otherwise take it for an inconsistency and « fix » it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Command line only.\n");

$ecrire = in_array('--ecrire', $argv, true);
$racine = dirname(__DIR__);

/**
 * The one collision resolved by hand, French msgid => the English msgid it must take.
 *
 * Applied BEFORE the map, so it wins over lang/en.php. See the header for the reason: without it,
 * French prints an agreement error on one of the two call sites.
 */
const ARBITRAGES = [
    'envoyé(s)' => 'delivered',
];

/** Files whose t() calls are rewritten: everything that runs, plus the dynamic declarations. */
function fichiersACorriger(string $racine): array
{
    $out = [];

    foreach ([$racine . '/src', $racine . '/views', $racine . '/bin'] as $dossier) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
    }

    foreach ((array) glob($racine . '/*.php') as $f) {
        $out[] = (string) $f;
    }

    $out[] = $racine . '/lang/_dynamiques.php';

    return array_values(array_unique(array_filter($out, 'is_file')));
}

/**
 * Rewrite the t() literals of one file's source.
 *
 * @param array<string,string> $map
 * @return array{0:string, 1:int, 2:list<string>} new source, replacements, msgids left untouched
 */
function reecrire(string $source, array $map): array
{
    $jetons = token_get_all($source);
    $sortie = '';
    $faits = 0;
    $inconnus = [];
    $n = count($jetons);

    for ($i = 0; $i < $n; $i++) {
        $j = $jetons[$i];

        // The sequence we are after: the function name `t`, an opening parenthesis, one string.
        // Anything else is copied through byte for byte.
        $estAppelT = is_array($j) && $j[0] === T_STRING && $j[1] === 't'
            && isset($jetons[$i + 1]) && $jetons[$i + 1] === '('
            && isset($jetons[$i + 2]) && is_array($jetons[$i + 2])
            && $jetons[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING;

        // `->t(` and `::t(` and `$x->t(` are NOT this function. Nor is a `function t(`.
        if ($estAppelT) {
            for ($k = $i - 1; $k >= 0; $k--) {
                $p = $jetons[$k];
                if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($p === '->' || $p === '::' || (is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))) {
                    $estAppelT = false;
                }
                break;
            }
        }

        if (! $estAppelT) {
            $sortie .= is_array($j) ? $j[1] : $j;

            continue;
        }

        $litteral = $jetons[$i + 2][1];
        $valeur = decoderLitteral($litteral);

        if ($valeur === null) {
            // An interpolated double-quoted string: not a msgid we can map, and not something to
            // touch. bin/i18n-audit.php already refuses those.
            $sortie .= $j[1];

            continue;
        }

        $cible = ARBITRAGES[$valeur] ?? ($map[$valeur] ?? null);

        if ($cible === null) {
            // Already English, or a msgid the map does not know. Reported, never guessed.
            if (! estDejaAnglais($valeur, $map)) {
                $inconnus[] = $valeur;
            }
            $sortie .= $j[1];

            continue;
        }

        $sortie .= 't(' . encoderLitteral($cible);
        $i += 2;
        $faits++;
    }

    return [$sortie, $faits, $inconnus];
}

/** A msgid is already English when it is one of the map's VALUES. */
function estDejaAnglais(string $valeur, array $map): bool
{
    static $valeurs = null;
    $valeurs ??= array_flip(array_values($map));

    return isset($valeurs[$valeur]);
}

/** The value of a single- or double-quoted literal, or null when it interpolates. */
function decoderLitteral(string $litteral): ?string
{
    $q = $litteral[0];

    if ($q === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], substr($litteral, 1, -1));
    }

    if ($q !== '"') {
        return null;
    }

    $corps = substr($litteral, 1, -1);

    // A double-quoted literal that interpolates is not a fixed msgid.
    if (preg_match('/(?<!\\\\)[$]|(?<!\\\\)\{\$/', $corps) === 1) {
        return null;
    }

    return stripcslashes($corps);
}

/** A msgid as a single-quoted PHP literal, escaped the only two ways that matter. */
function encoderLitteral(string $valeur): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $valeur) . "'";
}

// ---------------------------------------------------------------------------
// The map, and its own coherence checked before anything is rewritten
// ---------------------------------------------------------------------------
$map = require $racine . '/lang/en.php';

if (! is_array($map) || $map === []) {
    exit("lang/en.php is not a usable map: nothing can be migrated.\n");
}

$vides = array_filter($map, static fn ($v): bool => trim((string) $v) === '');

if ($vides !== []) {
    printf("REFUSED: %d entries of lang/en.php have no English side. Migrating would lose them.\n", count($vides));
    exit(2);
}

$jetonsFautifs = [];
foreach ($map as $fr => $en) {
    preg_match_all('/\{[a-z_]+\}/i', (string) $fr, $a);
    preg_match_all('/\{[a-z_]+\}/i', (string) $en, $b);
    sort($a[0]);
    sort($b[0]);
    if ($a[0] !== $b[0]) {
        $jetonsFautifs[] = $fr;
    }
}

if ($jetonsFautifs !== []) {
    printf("REFUSED: %d entries carry different {tokens} on each side. The message would break.\n", count($jetonsFautifs));
    foreach (array_slice($jetonsFautifs, 0, 5) as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(2);
}

printf("\nSource language switch: French -> English\n%s\n", str_repeat('─', 74));
printf("map: %d entries, no gaps, no token mismatch\n", count($map));

// ---------------------------------------------------------------------------
// The rewrite
// ---------------------------------------------------------------------------
$fichiers = fichiersACorriger($racine);
$total = 0;
$touches = 0;
$inconnus = [];

foreach ($fichiers as $chemin) {
    $source = (string) file_get_contents($chemin);
    [$neuf, $faits, $restes] = reecrire($source, $map);

    if ($faits === 0) {
        $inconnus = array_merge($inconnus, $restes);

        continue;
    }

    // A rewritten file MUST still parse. Refusing here rather than after the fact is the whole
    // point: a repository half-rewritten by a script that crashed is the worst outcome.
    if (! verifieSyntaxe($neuf)) {
        printf("REFUSED: %s no longer parses after rewriting. Nothing has been written.\n",
            substr($chemin, strlen($racine) + 1));
        exit(2);
    }

    $total += $faits;
    $touches++;
    $inconnus = array_merge($inconnus, $restes);

    if ($ecrire) {
        file_put_contents($chemin, $neuf);
    }
}

printf("%d call site(s) rewritten across %d file(s)\n", $total, $touches);

$inconnus = array_values(array_unique($inconnus));

if ($inconnus !== []) {
    printf("\n%d msgid(s) neither in the map nor already English:\n", count($inconnus));
    // TOUS, ET ENTIERS. La première version en montrait douze sur quinze et coupait à 90 signes :
    // une liste tronquée fait croire le travail plus petit qu'il est, et une phrase coupée ne se
    // recherche pas dans le code.
    foreach ($inconnus as $m) {
        echo '  - ' . $m . "\n";
    }
    echo "  These stay in French. Add them to lang/en.php and run again.\n";
}

/** Does this source parse? Written to a temporary file because php -l reads a file. */
function verifieSyntaxe(string $source): bool
{
    $tmp = tempnam(sys_get_temp_dir(), 'i18n');

    if ($tmp === false) {
        return false;
    }

    file_put_contents($tmp, $source);
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $sortie, $code);
    unlink($tmp);

    return $code === 0;
}

if (! $ecrire) {
    echo "\nNOTHING WAS WRITTEN. Run again with --ecrire.\n";
    echo "Then, in this order: bin/i18n-sync.php, then the suites. The catalogues are rebuilt\n";
    echo "by the same run, so a green selftest means the switch is complete.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// The catalogues: French becomes a translation, English becomes the source
// ---------------------------------------------------------------------------
//
// lang/fr.php is the INVERSE of the old lang/en.php. The arbitrated pair is written by hand,
// since the inverse of an override cannot be derived from the map that it overrides.
$fr = [];
foreach ($map as $francais => $anglais) {
    // First one wins: on the 23 collisions this keeps the entry whose French form is the one the
    // code actually used, because lang/en.php lists them in that order. The second form is not
    // lost silently, it is reported below.
    $fr[$anglais] ??= $francais;
}

foreach (ARBITRAGES as $francais => $anglais) {
    $fr[$anglais] = $francais;
}

ecrireCatalogue($racine . '/lang/fr.php', $fr,
    "French, now a TRANSLATION and no longer the source language.\n"
    . " *\n"
    . " * Generated by bin/i18n-basculer.php on the day the source language switched: this file is\n"
    . " * the inverse of the old lang/en.php. Twenty-three English strings were the target of two\n"
    . " * French forms (case and gender variants, which English does not have); the form the code\n"
    . " * actually used is the one kept here.");

// The nine partial catalogues are keyed in French: re-key them through the map.
$rekeys = [];
foreach ((array) glob($racine . '/lang/*.php') as $fichier) {
    $nom = basename((string) $fichier, '.php');

    if (in_array($nom, ['en', 'fr'], true) || str_starts_with($nom, '_')) {
        continue;
    }

    $avant = require $fichier;
    $apres = [];
    $perdus = 0;

    foreach ((array) $avant as $cle => $valeur) {
        $anglais = ARBITRAGES[$cle] ?? ($map[$cle] ?? null);

        if ($anglais === null) {
            $perdus++;

            continue;
        }

        $apres[$anglais] ??= $valeur;
    }

    ecrireCatalogue((string) $fichier, $apres,
        strtoupper($nom) . " catalogue, re-keyed on English msgids on the day the source language\n"
        . " * switched. Generated by bin/i18n-basculer.php.");

    $rekeys[$nom] = [count($apres), $perdus];
}

// lang/en.php becomes an EMPTY catalogue rather than being deleted: the loader expects a file per
// language, and an English catalogue that translates English into English would be 1 696 lines of
// nothing. Empty says the same thing and cannot drift.
ecrireCatalogue($racine . '/lang/en.php', [],
    "English is now the SOURCE language, so this catalogue is deliberately EMPTY.\n"
    . " *\n"
    . " * It used to map French msgids to English strings, which is what made French the source\n"
    . " * language of a repository whose every other line is English. Since the switch, t('...')\n"
    . " * carries the English text itself and there is nothing to translate here.\n"
    . " *\n"
    . " * The file stays rather than being deleted: the loader counts one catalogue per language,\n"
    . " * and bin/selftest.php counts the languages on the FILES of lang/. Deleting it would drop\n"
    . " * the announced language count by one, on the language that needs no translation.");

printf("\ncatalogues rebuilt:\n  lang/fr.php  %d entries\n  lang/en.php  empty, on purpose\n", count($fr));

foreach ($rekeys as $nom => [$gardees, $perdus]) {
    printf("  lang/%s.php  %d re-keyed%s\n", $nom, $gardees,
        $perdus > 0 ? sprintf(', %d dropped (no English side)', $perdus) : '');
}

echo "\nNow run, in this order: bin/i18n-sync.php then bin/selftest.php, bin/regles.php,\n";
echo "bin/deadcode.php, bin/i18n-audit.php. A green selftest means the switch is complete.\n";

/**
 * Write a catalogue, sorted, with its reason at the top.
 *
 * Sorted because a generated file whose order depends on a hash produces a diff nobody can read,
 * and this one will be regenerated.
 */
function ecrireCatalogue(string $chemin, array $entrees, string $entete): void
{
    ksort($entrees);

    $lignes = ["<?php", "", "/**", " * " . str_replace("\n", "\n", $entete), " */", "", "return ["];

    foreach ($entrees as $cle => $valeur) {
        $lignes[] = '    ' . encoderLitteral((string) $cle) . ' => ' . encoderLitteral((string) $valeur) . ',';
    }

    $lignes[] = "];";
    $lignes[] = "";

    file_put_contents($chemin, implode("\n", $lignes));
}
