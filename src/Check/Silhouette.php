<?php
declare(strict_types=1);

namespace Uptimeez\Check;

/**
 * Silhouette d'une page : ce qu'un visiteur verrait, reconstruit sans navigateur.
 *
 * Le problème que ça résout. UptimeEZ sait déjà dire « la feuille de style est en
 * 404 ». Mais un client ne discute pas un chiffre, il discute une image. Il faut
 * donc montrer la page telle qu'elle est devenue, à côté de ce qu'elle était.
 *
 * Or ouvrir un navigateur est exclu : le collecteur doit tourner sur un
 * mutualisé et vérifier des centaines de sites par minute. On reconstruit donc
 * une **silhouette** : la structure des blocs lue dans le HTML, mise en page
 * selon ce que le CSS réellement chargé permet de faire.
 *
 * Ce n'est pas une capture d'écran, et l'interface le dit. C'est une maquette
 * fonctionnelle, et c'est suffisant : quand le CSS tombe, la silhouette change
 * exactement comme la page change. Plus de conteneur centré, plus de colonnes,
 * tout empilé sur toute la largeur. C'est précisément ce que voit le visiteur.
 *
 * Trois étapes :
 *   1. lecture du HTML   -> un arbre de blocs (titre, texte, image, bouton…)
 *   2. lecture du CSS     -> ce que chaque bloc sait faire (centrer, aligner…)
 *   3. mise en page       -> des rectangles, rendus en SVG
 */
final class Silhouette
{
    /** Au-delà, une page n'apporte plus d'information : on plafonne le coût. */
    private const MAX_NODES = 90;
    private const MAX_DEPTH = 5;
    private const VIEW_W    = 360;
    private const VIEW_H    = 260;

    /** Balises de bloc qui portent une structure visible. */
    private const BLOCKS = [
        'header' => 'band', 'nav' => 'nav', 'main' => 'block', 'footer' => 'band',
        'section' => 'block', 'article' => 'block', 'aside' => 'block', 'div' => 'block',
        'ul' => 'list', 'ol' => 'list', 'li' => 'row', 'table' => 'list', 'form' => 'block',
        'h1' => 'title', 'h2' => 'title', 'h3' => 'subtitle', 'h4' => 'subtitle',
        'p' => 'text', 'blockquote' => 'text', 'figure' => 'image', 'img' => 'image',
        'picture' => 'image', 'video' => 'image', 'button' => 'button', 'a' => 'link',
        'input' => 'field', 'textarea' => 'field', 'select' => 'field', 'label' => 'text',
    ];

    /** Balises dont le contenu ne se voit pas. */
    private const SKIP = ['script', 'style', 'template', 'svg', 'noscript', 'head', 'iframe'];

    // =====================================================================
    // Entrée publique
    // =====================================================================
    /**
     * @return array{svg:string,signature:array,nodes:int}
     */
    public static function build(string $html, string $css): array
    {
        $tree  = self::parse($html);
        $rules = self::styleIndex($css);
        $boxes = [];
        $layout = self::layout($tree, $rules, 0, 0, self::VIEW_W, $boxes);
        $sig = self::signature($boxes, $layout['height']);
        return [
            'svg'       => self::render($boxes, $layout['height']),
            'signature' => $sig,
            'nodes'     => count($boxes),
        ];
    }

    /**
     * Écart entre deux silhouettes, de 0 (identiques) à 1 (méconnaissables).
     *
     * On compare des traits que le visiteur perçoit : la page est-elle encore
     * contenue en largeur, y a-t-il encore des colonnes, la hauteur a-t-elle
     * explosé, la variété des blocs a-t-elle disparu. Un écart de plus de 35 %
     * signifie qu'un visiteur voit une autre page.
     */
    public static function distance(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        $traits = [
            'contained' => 1.6,   // conteneur centré, largeur limitée
            'columns'   => 1.4,   // groupes horizontaux
            'height'    => 1.0,   // hauteur relative
            'variety'   => 0.8,   // diversité des types de blocs
            'density'   => 0.6,   // remplissage horizontal moyen
        ];
        $sum = 0.0; $weight = 0.0;
        foreach ($traits as $k => $w) {
            $x = (float)($a[$k] ?? 0);
            $y = (float)($b[$k] ?? 0);
            $max = max(abs($x), abs($y), 0.0001);
            $sum += $w * min(1.0, abs($x - $y) / $max);
            $weight += $w;
        }
        return $weight > 0 ? round($sum / $weight, 3) : 0.0;
    }

    // =====================================================================
    // 1. HTML -> arbre de blocs
    // =====================================================================
    /**
     * Analyse suffisante pour une silhouette : on suit la pile des balises
     * ouvrantes et on ne retient que les blocs porteurs de structure. Pas de
     * parseur XML, donc rien à exploiter pour une entité externe, et rien qui
     * s'étouffe sur du HTML mal formé — ce qui est la règle sur le web.
     */
    private static function parse(string $html): array
    {
        // On travaille sur le corps de page uniquement.
        if (preg_match('~<body\b[^>]*>(.*)</body>~is', $html, $m)) $html = $m[1];
        elseif (preg_match('~</head>(.*)$~is', $html, $m))         $html = $m[1];

        // Les zones invisibles disparaissent avant tout comptage.
        foreach (self::SKIP as $t) {
            $html = (string)preg_replace('~<' . $t . '\b.*?</' . $t . '>~is', '', $html);
        }

        $root  = ['kind' => 'root', 'classes' => [], 'text' => 0, 'children' => []];
        $stack = [&$root];
        $count = 0;

        // Un jeton : balise ouvrante, balise fermante, ou texte.
        $re = '~<(/?)([a-z][a-z0-9]*)\b([^>]*)>|([^<]+)~i';
        if (!preg_match_all($re, $html, $tokens, PREG_SET_ORDER)) return $root;

        foreach ($tokens as $tk) {
            // --- texte : il alimente le bloc courant ---
            if (($tk[4] ?? '') !== '') {
                $len = mb_strlen(trim(html_entity_decode($tk[4], ENT_QUOTES, 'UTF-8')));
                if ($len > 0) {
                    $top = &$stack[count($stack) - 1];
                    $top['text'] += $len;
                    unset($top);
                }
                continue;
            }
            $closing = ($tk[1] ?? '') === '/';
            $tag     = strtolower($tk[2] ?? '');
            $attrs   = $tk[3] ?? '';
            if (!isset(self::BLOCKS[$tag])) continue;

            if ($closing) {
                // On ne dépile que si ce tag a bien ouvert le bloc courant.
                for ($i = count($stack) - 1; $i > 0; $i--) {
                    if (($stack[$i]['tag'] ?? '') === $tag) {
                        array_splice($stack, $i);
                        break;
                    }
                }
                continue;
            }

            if ($count >= self::MAX_NODES || count($stack) > self::MAX_DEPTH) continue;

            $node = [
                'tag'      => $tag,
                'kind'     => self::BLOCKS[$tag],
                'classes'  => self::classes($attrs),
                'text'     => 0,
                'children' => [],
            ];
            // Un lien qui contient du texte court se lit comme un bouton.
            if ($tag === 'a' && preg_match('~\bclass="[^"]*\b(btn|button|cta)\b~i', $attrs)) {
                $node['kind'] = 'button';
            }
            $count++;

            $top = &$stack[count($stack) - 1];
            $top['children'][] = $node;
            $idx = count($top['children']) - 1;
            unset($top);

            // Les balises auto-fermantes n'empilent rien.
            if (!in_array($tag, ['img', 'input'], true) && !str_ends_with(rtrim($attrs), '/')) {
                $stack[] = &self::ref($stack, $idx);
            }
        }
        return $root;
    }

    /** Référence sur le dernier enfant ajouté au sommet de la pile. */
    private static function &ref(array &$stack, int $idx): array
    {
        $top = &$stack[count($stack) - 1];
        return $top['children'][$idx];
    }

    private static function classes(string $attrs): array
    {
        if (!preg_match('~\bclass\s*=\s*"([^"]*)"~i', $attrs, $m)
            && !preg_match("~\\bclass\\s*=\\s*'([^']*)'~i", $attrs, $m)) return [];
        $out = [];
        foreach (preg_split('~\s+~', trim($m[1])) ?: [] as $c) {
            if ($c !== '') $out[strtolower($c)] = true;
            if (count($out) >= 6) break;
        }
        return array_keys($out);
    }

    // =====================================================================
    // 2. CSS -> ce que chaque classe sait faire
    // =====================================================================
    /**
     * On n'implémente pas la cascade : on cherche, pour chaque classe, si une
     * règle quelque part lui donne une capacité de mise en page. C'est ce qui
     * compte ici, parce qu'une feuille de style absente les fait toutes
     * disparaître d'un coup.
     *
     * @return array{classes:array<string,array>,body:array,has_css:bool}
     */
    private static function styleIndex(string $css): array
    {
        $idx = ['classes' => [], 'body' => [], 'has_css' => trim($css) !== ''];
        if (!$idx['has_css']) return $idx;

        // Une règle : sélecteurs { déclarations }
        if (!preg_match_all('~([^{}@]{1,400})\{([^{}]{0,3000})\}~s', $css, $rules, PREG_SET_ORDER)) {
            return $idx;
        }
        foreach ($rules as $r) {
            $decl  = strtolower($r[2]);
            $caps  = [
                'flex'      => (bool)preg_match('~display\s*:\s*(flex|inline-flex)~', $decl),
                'grid'      => (bool)preg_match('~display\s*:\s*(grid|inline-grid)~', $decl),
                'maxw'      => (bool)preg_match('~max-width\s*:\s*\d~', $decl),
                'centered'  => (bool)preg_match('~margin\s*:[^;]*auto~', $decl)
                            || (bool)preg_match('~margin-(left|right|inline)\s*:\s*auto~', $decl),
                'padding'   => (bool)preg_match('~padding[^:]*:\s*[^0]~', $decl),
                'bg'        => (bool)preg_match('~background(-color|-image)?\s*:~', $decl),
                'radius'    => (bool)preg_match('~border-radius\s*:\s*[^0]~', $decl),
                'hidden'    => (bool)preg_match('~(display\s*:\s*none|opacity\s*:\s*0(\.0+)?\s*[;}])~', $decl),
                'columns'   => (bool)preg_match('~grid-template-columns\s*:~', $decl),
                'font_big'  => (bool)preg_match('~font-size\s*:\s*([2-9]|[1-9]\d)(\.\d+)?(rem|em)~', $decl),
                'center_txt'=> (bool)preg_match('~text-align\s*:\s*center~', $decl),
            ];
            if (!in_array(true, $caps, true)) continue;

            foreach (preg_split('~\s*,\s*~', trim($r[1])) ?: [] as $sel) {
                if (trim($sel) === 'body' || trim($sel) === 'html') {
                    $idx['body'] = self::mergeCaps($idx['body'], $caps);
                    continue;
                }
                // On retient la dernière classe du sélecteur : celle que porte
                // l'élément visé. Les échappements de Tailwind sont conservés.
                if (preg_match_all('~\.((?:\\\\.|[a-z0-9_-])+)~i', $sel, $cm)) {
                    $cls = strtolower(str_replace('\\', '', end($cm[1])));
                    $idx['classes'][$cls] = self::mergeCaps($idx['classes'][$cls] ?? [], $caps);
                }
            }
        }
        return $idx;
    }

    private static function mergeCaps(array $a, array $b): array
    {
        foreach ($b as $k => $v) $a[$k] = ($a[$k] ?? false) || $v;
        return $a;
    }

    /** Capacités effectives d'un nœud : union de celles de ses classes. */
    private static function caps(array $node, array $idx): array
    {
        $c = [];
        foreach ($node['classes'] as $cls) {
            if (isset($idx['classes'][$cls])) $c = self::mergeCaps($c, $idx['classes'][$cls]);
        }
        return $c;
    }

    // =====================================================================
    // 3. Mise en page
    // =====================================================================
    /**
     * Empilement vertical, avec passage en colonnes quand le CSS le permet.
     * Volontairement simple : on ne cherche pas la fidélité au pixel, on cherche
     * la différence entre « mis en page » et « pas mis en page ».
     */
    private static function layout(array $node, array $idx, float $x, float $y,
                                   float $width, array &$boxes, int $depth = 0): array
    {
        $caps = self::caps($node, $idx);
        $isRoot = ($node['kind'] ?? '') === 'root';

        // Un conteneur centré et borné : c'est la signature d'une page mise en
        // page. Sans CSS, rien ne borne plus rien et tout occupe la largeur.
        $inner = $width;
        $ix    = $x;
        if (!empty($caps['maxw']) && !empty($caps['centered'])) {
            $inner = $width * 0.78;
            $ix    = $x + ($width - $inner) / 2;
        } elseif (!empty($caps['padding'])) {
            $inner = $width * 0.94;
            $ix    = $x + ($width - $inner) / 2;
        }

        $children = $node['children'] ?? [];
        $cursorY  = $y + ($isRoot ? 0 : 3);

        // --- colonnes ------------------------------------------------------
        $horizontal = (!empty($caps['flex']) || !empty($caps['grid']) || !empty($caps['columns']))
                      && count($children) >= 2 && $depth < self::MAX_DEPTH;

        if ($horizontal) {
            $n     = min(count($children), 4);
            $gap   = 4;
            $colW  = ($inner - $gap * ($n - 1)) / $n;
            $maxH  = 0;
            for ($i = 0; $i < $n; $i++) {
                $r = self::layout($children[$i], $idx, $ix + $i * ($colW + $gap), $cursorY,
                                  $colW, $boxes, $depth + 1);
                $maxH = max($maxH, $r['height']);
            }
            $cursorY += $maxH;
        } else {
            foreach ($children as $child) {
                $r = self::layout($child, $idx, $ix, $cursorY, $inner, $boxes, $depth + 1);
                $cursorY += $r['height'];
            }
        }

        // --- le bloc lui-même ---------------------------------------------
        $ownHeight = 0.0;
        if (!$isRoot) {
            $ownHeight = self::ownHeight($node, $caps, $inner);
            if ($ownHeight > 0) {
                $boxes[] = [
                    'k' => $node['kind'],
                    'x' => round($ix, 1), 'y' => round($cursorY, 1),
                    'w' => round(self::ownWidth($node, $caps, $inner), 1),
                    'h' => round($ownHeight, 1),
                    'r' => !empty($caps['radius']) || $node['kind'] === 'button' ? 2 : 0,
                    'bg' => !empty($caps['bg']),
                    'c' => !empty($caps['center_txt']),
                    'faded' => !empty($caps['hidden']),
                ];
                $cursorY += $ownHeight + 2;
            }
        }
        return ['height' => max(0.0, $cursorY - $y)];
    }

    private static function ownHeight(array $node, array $caps, float $w): float
    {
        $text = (int)$node['text'];
        return match ($node['kind']) {
            'title'    => !empty($caps['font_big']) ? 11 : 8,
            'subtitle' => 6,
            'text'     => $text === 0 ? 0 : min(18, 3 + ceil($text / max(28, $w * 0.34)) * 3),
            'image'    => 26,
            'button'   => 7,
            'field'    => 6,
            'nav'      => 6,
            'band'     => $text > 0 || $node['children'] ? 0 : 8,
            'row'      => $text === 0 ? 0 : 4,
            'link'     => $text === 0 ? 0 : 3,
            default    => 0,   // les conteneurs ne se dessinent pas eux-mêmes
        };
    }

    private static function ownWidth(array $node, array $caps, float $w): float
    {
        return match ($node['kind']) {
            'button' => min(52.0, max(24.0, $w * 0.28)),
            'field'  => $w * 0.72,
            'title'  => !empty($caps['center_txt']) ? $w * 0.66 : $w * 0.82,
            'text'   => $w,
            'image'  => $w,
            default  => $w,
        };
    }

    // =====================================================================
    // Signature : ce qui permet de mesurer un changement
    // =====================================================================
    private static function signature(array $boxes, float $height): array
    {
        if (!$boxes) return ['contained' => 0, 'columns' => 0, 'height' => 0, 'variety' => 0, 'density' => 0];

        $full = 0; $narrow = 0; $sumW = 0.0;
        $rows = [];
        $kinds = [];
        foreach ($boxes as $b) {
            $sumW += $b['w'];
            if ($b['w'] > self::VIEW_W * 0.95) $full++;
            if ($b['x'] > 2) $narrow++;
            $rows[(int)round($b['y'] / 4)][] = $b;
            $kinds[$b['k']] = true;
        }
        // Des blocs sur une même rangée : la page a des colonnes.
        $columns = 0;
        foreach ($rows as $r) if (count($r) > 1) $columns++;

        return [
            // Part des blocs qui ne touchent pas le bord : conteneur centré.
            'contained' => round($narrow / count($boxes), 3),
            'columns'   => $columns,
            'height'    => round($height, 1),
            'variety'   => count($kinds),
            'density'   => round(($sumW / count($boxes)) / self::VIEW_W, 3),
        ];
    }

    // =====================================================================
    // Rendu SVG
    // =====================================================================
    /**
     * Un SVG autonome, sans police externe ni script : il s'affiche dans la
     * fiche de sonde comme dans un rapport imprimé, et se stocke en base sans
     * dépendre de rien.
     */
    private static function render(array $boxes, float $height): string
    {
        $h = max(self::VIEW_H, min(900.0, $height + 8));
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::VIEW_W . ' ' . (int)$h . '"'
             . ' width="100%" role="img" class="silhouette">';
        $out .= '<rect width="' . self::VIEW_W . '" height="' . (int)$h . '" fill="#fbfcfe"/>';

        static $fill = [
            'title'    => '#334155',
            'subtitle' => '#475569',
            'text'     => '#cbd5e1',
            'image'    => '#94a3b8',
            'button'   => '#3b5bdb',
            'field'    => '#e2e8f0',
            'nav'      => '#64748b',
            'row'      => '#dbe2ea',
            'link'     => '#93a3b8',
            'band'     => '#eef2f7',
        ];
        foreach ($boxes as $b) {
            $c = $fill[$b['k']] ?? '#e2e8f0';
            $o = $b['faded'] ? '0.25' : '1';
            $out .= '<rect x="' . $b['x'] . '" y="' . $b['y'] . '" width="' . $b['w']
                  . '" height="' . $b['h'] . '" rx="' . $b['r'] . '" fill="' . $c
                  . '" opacity="' . $o . '"/>';
            // Une image se signale par sa diagonale, comme dans une maquette.
            if ($b['k'] === 'image' && $b['w'] > 8 && $b['h'] > 8) {
                $out .= '<path d="M' . $b['x'] . ' ' . ($b['y'] + $b['h']) . 'L'
                      . ($b['x'] + $b['w']) . ' ' . $b['y'] . '" stroke="#fff" stroke-width="0.7"'
                      . ' opacity="0.6" fill="none"/>';
            }
        }
        return $out . '</svg>';
    }
}
