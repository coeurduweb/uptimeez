#!/usr/bin/env php
<?php
/**
 * Readability, measured rather than judged: WCAG contrast on every colour pair the interface
 * actually serves, in BOTH themes, plus the two other things that make a dense dashboard
 * unreadable — text that got too small, and table rows that got too tight.
 *
 * ------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ------------------------------------------------------------------------------
 *
 * "Review the contrasts, the sizes and the table density" sat in the backlog for a week as the
 * last interface item, and it could not be closed, because nothing about it was measurable.
 * A human looking at a screen and saying "that looks fine" produces a verdict that cannot be
 * repeated, cannot be regressed against, and cannot be argued with. Two people disagree and
 * there is no third thing to consult.
 *
 * Contrast is not a matter of taste. It is a ratio between two relative luminances, defined by
 * WCAG 2.1, and it either clears 4.5:1 for body text or it does not. So this file computes it.
 *
 * ------------------------------------------------------------------------------
 * THE PAIRS ARE DECLARED, AND THAT IS DELIBERATE
 * ------------------------------------------------------------------------------
 *
 * Deriving "which colour sits on which background" from CSS automatically would mean resolving
 * the cascade, and a wrong pair is worse than no pair: it produces a failure nobody can act on,
 * and the check gets switched off. The list below is therefore written by hand, and every entry
 * names WHERE it is served. If a pair disappears from the stylesheet the check says so; if a new
 * one appears, the list has to grow, and that is a conscious act.
 *
 * The values themselves are NOT copied: they are read from assets/app.css at run time, for both
 * themes. A copied colour is a colour that will diverge.
 *
 * ------------------------------------------------------------------------------
 * TWO THRESHOLDS, AND ONE THING DELIBERATELY LEFT WITHOUT ONE
 * ------------------------------------------------------------------------------
 *
 *   4.5:1  body text (WCAG 1.4.3 AA). The only floor that matters for a to-do list read every
 *          morning, and the reason the three verdict inks exist at all.
 *   3.0:1  the visible edge of a CONTROL (WCAG 1.4.11). An input you cannot find is an input
 *          that does not exist.
 *
 * And no threshold at all on a card against the page behind it. The first version of this file
 * demanded 3:1 there, both themes failed, and the standard turned out not to ask for it: a
 * card's fill is neither a control nor a graphical object required to understand the content.
 * Enforcing it would have meant a near-black dashboard to satisfy a number I invented. The
 * figures are still printed, and what a card must have is checked structurally — an edge and an
 * elevation — because that is what actually tells the eye where one site ends. See the comment
 * on $informatives: **a threshold is part of what gets reviewed.**
 *
 * WHAT THIS FILE CANNOT SEE, and it has to be said rather than implied: it reads the stylesheet,
 * so it knows nothing about text over an image, about a colour computed by color-mix() (reported
 * as "not measurable" rather than skipped), or about a contrast that only breaks once a browser
 * has composed the page. It closes the class of defect where a value is simply too low.
 *
 * Usage:
 *   php bin/lisibilite.php
 */
declare(strict_types=1);

// Never served over HTTP, like every script in bin/. bin/security.php checks this file by file.
if (PHP_SAPI !== 'cli') exit("Command line only.\n");

$css = (string) file_get_contents(__DIR__ . '/../assets/app.css');

/**
 * The custom properties of one theme block.
 *
 * @return array<string,string> name without the leading dashes => value
 */
function variables(string $css, string $selecteur): array
{
    // The block is taken up to the first closing brace at the start of a line: nested rules
    // would otherwise swallow the following theme.
    if (preg_match('/' . preg_quote($selecteur, '/') . '\s*\{(.*?)\n\}/s', $css, $m) !== 1) {
        return [];
    }

    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $m[1], $paires, PREG_SET_ORDER);

    $out = [];
    foreach ($paires as $p) {
        $out[strtolower($p[1])] = trim($p[2]);
    }

    return $out;
}

/** @return array{0:float,1:float,2:float}|null */
function rvb(string $hex): ?array
{
    $hex = ltrim(trim($hex), '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return null;
    }

    return [
        (float) hexdec(substr($hex, 0, 2)),
        (float) hexdec(substr($hex, 2, 2)),
        (float) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Relative luminance, WCAG 2.1 §relative-luminance. The 0.03928 threshold and the 2.4
 * exponent are the specification's, not a rounding of it.
 */
function luminance(array $rvb): float
{
    $c = array_map(static function (float $v): float {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, $rvb);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** The contrast ratio between two hex colours, or 0 if either is unreadable. */
function contraste(string $a, string $b): float
{
    $ra = rvb($a);
    $rb = rvb($b);

    if ($ra === null || $rb === null) {
        return 0.0;
    }

    $la = luminance($ra);
    $lb = luminance($rb);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * THE PAIRS. Each one: foreground variable, background variable, required ratio, and where it
 * is served — the last field being the one that makes a failure actionable.
 *
 * @var list<array{0:string,1:string,2:float,3:string}>
 */
$paires = [
    // The text you read all day.
    ['text',      'surface',   4.5, 'card and table body text'],
    ['text',      'bg',        4.5, 'text directly on the page background'],
    ['text',      'surface-2', 4.5, 'hovered table row, secondary panels'],
    ['text-soft', 'surface',   4.5, 'secondary sentences inside a card'],
    ['text-soft', 'bg',        4.5, 'secondary sentences on the page background'],
    ['muted',     'surface',   4.5, 'labels, timestamps, chip text'],
    ['muted',     'surface-2', 4.5, 'labels on a hovered row'],
    ['muted',     'bg',        4.5, 'labels on the page background'],

    // The three verdict inks. They exist BECAUSE the flat colours failed here: the comment in
    // assets/app.css records "opérationnel" written in --ok measuring 4.1:1 on white.
    ['ok-ink',   'surface',   4.5, 'the word for a healthy state, on a card'],
    ['ok-ink',   'ok-soft',   4.5, 'healthy badge'],
    ['warn-ink', 'surface',   4.5, 'the wording of a degraded state, on a card'],
    ['warn-ink', 'warn-soft', 4.5, 'degraded badge'],
    ['bad-ink',  'surface',   4.5, 'the wording of an outage, on a card'],
    ['bad-ink',  'bad-soft',  4.5, 'outage badge'],

    // Links and the primary button. A link that fails here fails on every screen at once.
    ['accent',     'surface', 4.5, 'links inside a card'],
    ['accent',     'bg',      4.5, 'links on the page background'],
    ['on-accent',  'accent',  4.5, 'the label of the primary button'],
    ['on-accent',  'accent-hover', 4.5, 'the primary button under the pointer'],

    // Non-text, 3:1, WCAG 1.4.11: the boundary IS the information. This is the visible edge of
    // a CONTROL — input, select, button, command palette, segmented switch — and a control you
    // cannot find is a control that does not exist.
    ['border-strong', 'surface', 3.0, 'the edge of an input, a select, a button'],
];

/**
 * MEASURED, REPORTED, NOT REQUIRED. And this list exists because my own threshold was wrong.
 *
 * The first version of this file demanded 3:1 between a card and the page behind it, and both
 * themes failed it — 1.08:1 and 1.10:1. Then the standard was read instead of remembered.
 * WCAG 1.4.11 covers "user interface components" and "graphical objects required to understand
 * the content": a card's fill is neither. The card is identified by its border and its shadow,
 * which is why the requirement below is STRUCTURAL rather than chromatic.
 *
 * Demanding 3:1 here would have meant either a near-black page or near-black cards, on a
 * dashboard read all day. I would have made the product worse to satisfy a number I invented.
 * That is the mistake this list is here to remember: **a threshold is part of what gets
 * reviewed**, and a guard that forces a correct design to change gets switched off by whoever
 * it annoys — the exact lesson paid on the site's own test suite the same day.
 *
 * Reported anyway: a card that dissolves into the page is a real defect, it is just not one a
 * ratio can decide. The figures are printed so a human can look at them.
 *
 * @var list<array{0:string,1:string,2:string}>
 */
$informatives = [
    ['surface',   'bg',      'a card against the page behind it'],
    ['border',    'surface', 'the soft edge of a card — decorative, see above'],
    ['surface-2', 'surface', 'a hovered table row against the card it sits in'],
];

/**
 * Font sizes: the floor is 12px, and it is not arbitrary.
 *
 * This product is read on a phone at seven in the morning by someone who has just been told a
 * client's site is down. Below 12px, a dense table stops being scannable, and the parts that
 * shrink first are always the same: chips, segmented controls, monospace paths — that is, the
 * evidence.
 *
 * Declared exceptions carry their reason, like everywhere else in this engine.
 */
$plancherPx = 12.0;
$exceptionsTaille = [
    // Nothing yet. An exception here means a piece of text nobody can read on a phone.
];

$echecs = [];
$avertissements = [];

echo "\nReadability, measured: contrast, type size, table density\n";
echo str_repeat('─', 78) . "\n";

// ---------------------------------------------------------------------------
// 1. Contrast, in both themes
// ---------------------------------------------------------------------------
$themes = [
    'light' => variables($css, ':root'),
    'dark'  => variables($css, 'html[data-theme="dark"]'),
];

foreach ($themes as $nom => $vars) {
    if ($vars === []) {
        $echecs[] = "theme « $nom »: no custom properties found in assets/app.css. Either the "
                  . 'selector changed, or this check is now measuring nothing.';

        continue;
    }

    printf("\nContrast — %s theme\n", $nom);

    foreach ($paires as [$avant, $arriere, $minimum, $ou]) {
        // A dark theme may legitimately reuse a light-theme value it does not override.
        $cAvant   = $vars[$avant]   ?? $themes['light'][$avant]   ?? null;
        $cArriere = $vars[$arriere] ?? $themes['light'][$arriere] ?? null;

        if ($cAvant === null || $cArriere === null) {
            $echecs[] = sprintf(
                '%s: --%s or --%s no longer exists. This pair used to be served on %s; the '
                . 'check cannot silently stop covering it.',
                $nom, $avant, $arriere, $ou
            );
            printf("  ❌ %-26s %s\n", "--$avant / --$arriere", 'MISSING VARIABLE');

            continue;
        }

        $ratio = contraste($cAvant, $cArriere);

        if ($ratio === 0.0) {
            // color-mix() and friends: not a failure, but not measured either, and saying so is
            // the whole point. A check that quietly skips is a check that lies by omission.
            $avertissements[] = sprintf(
                '%s: --%s on --%s is not a plain hex colour (%s on %s), so it is NOT measured. '
                . 'Served on %s.',
                $nom, $avant, $arriere, $cAvant, $cArriere, $ou
            );
            printf("  ⚠️  %-26s not measurable (%s on %s)\n", "--$avant / --$arriere", $cAvant, $cArriere);

            continue;
        }

        $tenu = $ratio >= $minimum;
        printf(
            "  %s %-26s %5.2f:1  (min %.1f)  %s\n",
            $tenu ? '✅' : '❌', "--$avant / --$arriere", $ratio, $minimum, $ou
        );

        if (! $tenu) {
            $echecs[] = sprintf(
                '%s: --%s on --%s measures %.2f:1, below the %.1f:1 required. Served on %s.',
                $nom, $avant, $arriere, $ratio, $minimum, $ou
            );
        }
    }

    // Printed, never judged. See the comment on $informatives: the threshold was the thing
    // that was wrong here, not the design.
    foreach ($informatives as [$avant, $arriere, $ou]) {
        $cAvant   = $vars[$avant]   ?? $themes['light'][$avant]   ?? null;
        $cArriere = $vars[$arriere] ?? $themes['light'][$arriere] ?? null;

        if ($cAvant === null || $cArriere === null) {
            continue;
        }

        printf("  ·  %-26s %5.2f:1  (no floor)  %s\n", "--$avant / --$arriere",
            contraste($cAvant, $cArriere), $ou);
    }
}

// ---------------------------------------------------------------------------
// 1 bis. What actually makes a card a card, since its fill does not
// ---------------------------------------------------------------------------
//
// This replaces the chromatic requirement that was wrong. A card is identified by its edge and
// its elevation; if BOTH disappear, the day screen becomes a flat wall of text, and no colour
// ratio would have caught it.
echo "\nWhat identifies a card (its fill is 1.08:1 against the page, on purpose)\n";

if (preg_match('/(^|\})\s*\.card\s*\{(.*?)\n\}/s', $css, $bloc) !== 1) {
    $echecs[] = '.card no longer exists in assets/app.css: the day screen is made of them, so '
              . 'either it was renamed and this check covers nothing, or something larger broke.';
    echo "  ❌ .card not found\n";
} else {
    foreach (['border' => 'an edge', 'box-shadow' => 'an elevation'] as $propriete => $quoi) {
        if (preg_match('/\b' . $propriete . '\s*:/', $bloc[2]) === 1) {
            printf("  ✅ %-12s %s\n", $propriete, $quoi);
        } else {
            $echecs[] = sprintf(
                '.card declares no %s. Its fill is nearly the colour of the page (measured '
                . 'above), so %s was the only thing left telling the eye where one site ends '
                . 'and the next begins.',
                $propriete, $quoi
            );
            printf("  ❌ %-12s missing\n", $propriete);
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Type size
// ---------------------------------------------------------------------------
echo "\nType size (floor: " . $plancherPx . "px)\n";

preg_match_all('/font-size\s*:\s*([0-9.]+)(px|rem|em)/i', $css, $tailles, PREG_SET_ORDER);

$parTaille = [];

foreach ($tailles as $t) {
    $valeur = (float) $t[1];
    // 16px root: the stylesheet never overrides html { font-size }, checked below.
    $px = strtolower($t[2]) === 'px' ? $valeur : $valeur * 16.0;
    $cle = sprintf('%.2f', $px);
    $parTaille[$cle] = ($parTaille[$cle] ?? 0) + 1;
}

if (preg_match('/(^|\})\s*html\s*\{[^}]*font-size/s', $css) === 1) {
    $avertissements[] = 'assets/app.css sets font-size on html: the rem → px conversion above '
                      . 'assumes a 16px root and would be wrong.';
}

ksort($parTaille, SORT_NUMERIC);

foreach ($parTaille as $px => $combien) {
    $valeur = (float) $px;
    $tenu = $valeur >= $plancherPx;
    printf("  %s %6.2fpx  %d declaration(s)\n", $tenu ? '✅' : '❌', $valeur, $combien);

    if (! $tenu) {
        $echecs[] = sprintf(
            '%.2fpx appears in %d declaration(s), below the %.0fpx floor. On a phone at seven '
            . 'in the morning, that is the evidence nobody can read.',
            $valeur, $combien, $plancherPx
        );
    }
}

// ---------------------------------------------------------------------------
// 3. Table density
// ---------------------------------------------------------------------------
//
// A dense dashboard is the point: fewer scrolls means fewer sites missed. But a row is a touch
// target as well as a line of text, and 44px is the smallest one a thumb hits reliably. The
// vertical padding of a cell plus one line of text is what decides that, so it is measured
// rather than eyeballed.
echo "\nTable density (a row must stay reachable with a thumb)\n";

$plancherLigne = 36.0;   // padding + one line: below this a row stops being a target
$hauteurLigne  = 20.0;   // one 14px line at the stylesheet's line-height

preg_match_all(
    '/table\.tbl\s+(?:th|td)[^{]*\{[^}]*padding\s*:\s*([0-9.]+)px/i',
    $css,
    $paddings,
    PREG_SET_ORDER
);

if ($paddings === []) {
    $echecs[] = 'no padding found on table.tbl th/td: either the tables changed shape, or this '
              . 'part of the check stopped covering anything.';
    echo "  ❌ no measurable padding\n";
}

foreach ($paddings as $p) {
    $padding = (float) $p[1];
    $ligne = 2 * $padding + $hauteurLigne;
    $tenu = $ligne >= $plancherLigne;

    printf(
        "  %s padding %4.1fpx → row about %4.1fpx (floor %.0f)\n",
        $tenu ? '✅' : '❌', $padding, $ligne, $plancherLigne
    );

    if (! $tenu) {
        $echecs[] = sprintf(
            'a table row measures about %.1fpx (padding %.1fpx + one line): below %.0fpx a row '
            . 'stops being a reliable touch target.',
            $ligne, $padding, $plancherLigne
        );
    }
}

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('═', 78) . "\n";

foreach ($avertissements as $a) {
    echo "⚠️  $a\n";
}

if ($echecs !== []) {
    printf("\n%d problem(s):\n", count($echecs));
    foreach ($echecs as $e) {
        echo "  - $e\n";
    }
    echo "\nNothing looks broken on screen. That is the point: unreadable is not the same as\n";
    echo "wrong, and only one of the two is visible from a screenshot.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// The READMEs must announce the number this suite actually runs
// ---------------------------------------------------------------------------
//
// Same guard as bin/regles.php, for the same reason and posted the same day: that file
// announced 138 checks while running 152, and nothing could have said so. bin/selftest.php
// verifies the README's grand total equals the SUM of its table — which it did. The table was
// internally consistent and externally wrong, and selftest says in its own comment that it
// takes the other suites' figures on trust, because checking them would mean running them.
//
// The suite that knows the number guards it. This one needs nothing but the stylesheet.
$verdicts = 2 * (count($paires)) + 2 + count($parTaille) + count($paddings);
$readmesFaux = [];

foreach (['README.md' => '/php bin\/lisibilite\.php\s+([\d,\x{202f}\x{00a0} ]+) checks/u',
          'README.fr.md' => '/php bin\/lisibilite\.php\s+([\d,\x{202f}\x{00a0} ]+) contrôles/u'] as $nom => $motif) {
    $chemin = __DIR__ . '/../' . $nom;

    if (! is_file($chemin)) {
        $readmesFaux[] = "$nom is missing";

        continue;
    }

    if (preg_match($motif, (string) file_get_contents($chemin), $m) !== 1) {
        $readmesFaux[] = "$nom no longer announces this suite at all";

        continue;
    }

    $annonce = (int) preg_replace('/\D/', '', $m[1]);

    if ($annonce !== $verdicts) {
        $readmesFaux[] = sprintf('%s announces %d checks, this suite runs %d', $nom, $annonce, $verdicts);
    }
}

if ($readmesFaux !== []) {
    echo "\n⚠️  The READMEs do not tell the truth about this suite:\n";
    foreach ($readmesFaux as $f) {
        echo "  - $f\n";
    }
    echo "  Fix the line AND the grand total: bin/selftest.php checks that the total is the sum\n";
    echo "  of its table, so changing one without the other moves the failure.\n";
    exit(1);
}

printf(
    "\n✅ %d verdicts: %d required pair(s) and %d informational pair(s) in 2 themes, what makes "
    . "a card, %d type size(s), %d table density rule(s).\n",
    $verdicts, count($paires), count($informatives), count($parTaille), count($paddings)
);
exit(0);
