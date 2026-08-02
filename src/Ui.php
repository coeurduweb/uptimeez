<?php
declare(strict_types=1);

namespace Uptimeez;

/** Fabrique de fragments HTML/SVG pour les vues (aucune dépendance front). */
final class Ui
{
    /**
     * Les libellés restent en clair : une constante ne peut pas appeler de
     * fonction. La traduction a lieu dans statusLabel().
     */
    public const LABELS = [
        'up'       => 'Opérationnel',
        'degraded' => 'À surveiller',
        'down'     => 'Hors service',
        'paused'   => 'En pause',
        'unknown'  => 'Pas encore vérifié',
    ];

    /** Jeu d'icônes unique, trait 1.8, 24x24 : jamais d'emoji dans l'interface. */
    private const ICONS = [
        'pulse'     => '<path d="M2 12h4l3 8 4-16 3 8h6"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'alert'     => '<path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>',
        'pause'     => '<rect x="7" y="5" width="3.5" height="14" rx="1"/><rect x="13.5" y="5" width="3.5" height="14" rx="1"/>',
        'play'      => '<path d="M8 5v14l11-7z"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/>',
        'external'  => '<path d="M18 13v6H5V6h6M14 4h6v6M20 4 10 14"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'chevron'   => '<path d="m9 6 6 6-6 6"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'lock'      => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5zM3 13l9 5 9-5"/>',
        'db'        => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'bell'      => '<path d="M18 9a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7M10.3 21a2 2 0 0 0 3.4 0"/>',
        'sliders'   => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'trash'     => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13"/>',
        'download'  => '<path d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'history'   => '<path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-7 3.4M3 4v4h4"/><path d="M12 8v4l3 2"/>',
        'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
        'file'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'eye'       => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 20c0-3.4 2.8-5.5 6.2-5.5s6.2 2.1 6.2 5.5"/><path d="M16.2 5.4a3 3 0 0 1 0 5.4M21.2 20c0-2.3-1-4-2.8-4.9"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 3 3-3 3-2-2"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>',
        'wrench'    => '<path d="M15 6a4 4 0 1 0 4 4l-9 9a3 3 0 0 1-4-4l9-9z"/>',
    ];

    public static function icon(string $name, int $size = 16, string $class = ''): string
    {
        $body = self::ICONS[$name] ?? self::ICONS['info'];
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true">' . $body . '</svg>';
    }

    /**
     * Le sigle du produit : quatre barres, dont une plus haute.
     *
     * C'est la frise 30 jours de l'interface, le seul élément visuel qui
     * appartienne déjà au produit. Et il ne dit pas « tout va bien » : il dit
     * « voilà la seule chose à regarder », donc le positionnement lui-même.
     *
     * Pourquoi une fonction à part et non une entrée de ICONS : le jeu d'icônes
     * est fait de tracés au trait dans une grille 24, sans remplissage, colorés par
     * « currentColor ». Le sigle est fait de rectangles pleins et de trois couleurs
     * fixes. L'y faire entrer aurait demandé d'affaiblir le gabarit commun pour un
     * seul cas.
     *
     * Les couleurs ne sont pas paramétrables : le vert et l'ambre du sigle sont la
     * marque, pas un thème. Un sigle qui change de couleur avec l'interface ne se
     * reconnaît plus.
     */
    public static function brand(int $height = 21): string
    {
        return '<svg width="' . $height . '" height="' . $height . '" viewBox="0 0 64 64"'
            . ' aria-hidden="true" style="vertical-align:-.18em;flex:0 0 auto">'
            . '<rect width="64" height="64" rx="14" fill="#080b12"/>'
            . '<g fill="#34c785">'
            . '<rect x="9" y="16" width="8" height="32" rx="4"/>'
            . '<rect x="20.5" y="16" width="8" height="32" rx="4"/>'
            . '<rect x="43.5" y="16" width="8" height="32" rx="4"/>'
            . '</g>'
            . '<rect x="32" y="10" width="8" height="44" rx="4" fill="#f0ad3c"/>'
            . '</svg>';
    }

    /**
     * Cadences proposées, libellées et traduites.
     *
     * Une seule liste pour l'écran d'import et pour la fiche d'une sonde : deux
     * copies finissaient par diverger, et par n'être traduites qu'à moitié.
     *
     * @return array<int,string>
     */
    public static function intervalChoices(bool $withExtremes = true): array
    {
        $out = [];
        if ($withExtremes) $out[30] = t('Toutes les 30 secondes');
        $out += [
            60   => t('Toutes les minutes'),
            120  => t('Toutes les 2 minutes'),
            300  => t('Toutes les 5 minutes'),
            600  => t('Toutes les 10 minutes'),
            900  => t('Toutes les 15 minutes'),
            1800 => t('Toutes les 30 minutes'),
            3600 => t('Toutes les heures'),
        ];
        if ($withExtremes) {
            $out[21600] = t('Toutes les 6 heures');
            $out[86400] = t('Une fois par jour');
        }
        return $out;
    }

    public static function statusLabel(?string $s): string
    {
        return I18n::t(self::LABELS[$s ?? 'unknown'] ?? 'Inconnu');
    }

    // -----------------------------------------------------------------------
    // Niveau de détail de l'interface
    // -----------------------------------------------------------------------
    /**
     * « simple » ne montre que ce sur quoi on agit ; « expert » ouvre les
     * réglages fins, les mesures détaillées et les écrans d'analyse.
     *
     * Par défaut : simple. C'est le pari du produit : on ne fait pas payer à
     * tout le monde la complexité dont une minorité a besoin.
     */
    public const MODES = ['simple', 'expert'];

    public static function mode(): string
    {
        static $mode = null;
        if ($mode !== null) return $mode;
        $raw = (string)($_COOKIE['uptimeez_mode'] ?? $_SESSION['uptimeez_mode'] ?? '');
        if (!in_array($raw, self::MODES, true)) {
            $raw = (string)Config::get('app.ui_mode', 'simple');
        }
        return $mode = in_array($raw, self::MODES, true) ? $raw : 'simple';
    }

    public static function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) return;
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['uptimeez_mode'] = $mode;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('uptimeez_mode', $mode, [
                'expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['uptimeez_mode'] = $mode;
    }

    /**
     * Nombre formaté selon la langue (séparateurs décimal et de milliers).
     * Le cache est indexé par langue : une seule requête peut en changer.
     */
    public static function num(float $v, int $dec = 0): string
    {
        static $cache = [];
        $lang = I18n::lang();
        if (!isset($cache[$lang])) {
            $cache[$lang] = match ($lang) {
                'fr', 'ru'  => [',', ' '],   // espace fine insécable
                'es', 'pt'  => [',', '.'],
                default     => ['.', ','],
            };
        }
        [$dsep, $tsep] = $cache[$lang];
        return number_format($v, $dec, $dsep, $tsep);
    }

    public static function statusIcon(?string $s): string
    {
        return match ($s) {
            'up'       => self::icon('check', 20),
            'degraded' => self::icon('clock', 20),
            'down'     => self::icon('alert', 20),
            'paused'   => self::icon('pause', 20),
            default    => self::icon('info', 20),
        };
    }

    public static function dot(?string $status, string $label = ''): string
    {
        $s = $status ?: 'unknown';
        return '<span class="dot dot-' . e($s) . '" role="img" aria-label="'
             . e($label !== '' ? $label : self::statusLabel($s)) . '"></span>';
    }

    public static function badge(string $text, string $tone = 'neutral', ?string $title = null): string
    {
        return '<span class="badge badge-' . e($tone) . '"'
             . ($title ? ' title="' . e($title) . '"' : '') . '>' . e($text) . '</span>';
    }

    public static function pct(?float $v, int $dec = 2): string
    {
        return $v === null ? '—' : self::num($v, $dec) . ' %';
    }

    public static function ms(?int $v): string
    {
        if ($v === null) return '—';
        return $v >= 1000 ? self::num($v / 1000, 2) . ' s' : $v . ' ms';
    }

    public static function uptimeTone(?float $v): string
    {
        if ($v === null) return 'neutral';
        if ($v >= 99.9) return 'ok';
        if ($v >= 99.0) return 'warn';
        return 'bad';
    }

    /**
     * Ouvre un accordéon.
     * @param string $tone none|attn|warn
     */
    public static function accOpen(string $id, string $icon, string $title, string $note = '',
                                  bool $open = false, string $tone = 'none', string $badge = ''): string
    {
        $cls = 'acc' . ($tone === 'attn' ? ' acc-attn' : ($tone === 'warn' ? ' acc-warn' : ''));
        return '<details class="' . $cls . '" id="' . e($id) . '" data-acc="' . e($id) . '"' . ($open ? ' open' : '') . '>'
            . '<summary>'
            . '<span class="acc-icon">' . self::icon($icon, 18) . '</span>'
            . '<span class="acc-title">' . e($title) . '</span>'
            . ($badge !== '' ? $badge : '')
            . ($note !== '' ? '<span class="acc-note">' . e($note) . '</span>' : '')
            . '<span class="chev">' . self::icon('chevron', 16) . '</span>'
            . '</summary>';
    }

    public static function accBody(bool $tight = false): string
    {
        return '<div class="acc-body' . ($tight ? ' tight' : '') . '">';
    }

    public static function accClose(): string
    {
        return '</div></details>';
    }

    // =====================================================================
    // Graphiques
    // =====================================================================

    /** Mini-frise : une barre par intervalle, hauteur = temps de réponse. */
    public static function sparkline(array $buckets, int $w = 300, int $h = 34): string
    {
        $n = count($buckets);
        if ($n === 0) return '<div class="spark-empty">' . e(t('Pas encore de mesure')) . '</div>';

        $filled = 0;
        foreach ($buckets as $b) if (($b['state'] ?? 'none') !== 'none') $filled++;
        if ($filled === 0) return '<div class="spark-empty">' . e(t('Aucune mesure sur la période')) . '</div>';
        if ($filled < 3) {
            return '<div class="spark-empty">'
                 . e(tn($filled, 'Historique en cours : une mesure', 'Historique en cours : {n} mesures'))
                 . '</div>';
        }

        $vals = array_values(array_filter(array_map(fn($b) => (int)($b['avg_ms'] ?? 0), $buckets)));
        sort($vals);
        $max = $vals ? max(1, (int)($vals[(int)floor(count($vals) * 0.9)] ?? end($vals)) * 1.5) : 1;
        $max = max($max, 60);

        $gap = $n > 90 ? 0.5 : 1;
        $bw  = max(1.0, ($w - ($n - 1) * $gap) / $n);
        $svg = '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img"'
             . ' aria-label="' . e(tn($n, 'Historique du dernier intervalle',
                                         'Historique des {n} derniers intervalles')) . '">';
        $x = 0.0;
        foreach ($buckets as $b) {
            $state = (string)($b['state'] ?? 'none');
            $ms    = (int)($b['avg_ms'] ?? 0);
            $bh    = $state === 'none' ? 2 : max(3, min($h, ($ms / $max) * ($h - 3)));
            if ($state === 'down') $bh = $h;
            $svg .= '<rect class="b-' . $state . '" x="' . round($x, 2) . '" y="' . round($h - $bh, 2)
                 . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" rx="' . ($bw > 3 ? 1 : 0) . '">'
                 . '<title>' . e(self::bucketTitle($b)) . '</title></rect>';
            $x += $bw + $gap;
        }
        return $svg . '</svg>';
    }

    /**
     * Le pouls du parc : une bande de tranches colorées, la journée en un coup
     * d'œil.
     *
     * Pas de courbe, pas d'axe, pas de légende : la couleur suffit, et chaque
     * tranche porte son détail dans une infobulle native. Une bande plutôt
     * qu'un graphique, parce qu'on cherche à répondre « est-ce que la journée
     * s'est bien passée ? » et pas « combien de millisecondes à 14 h 20 ? ».
     */
    public static function pulse(array $buckets, int $h = 26): string
    {
        $n = count($buckets);
        if ($n === 0) return '';
        $gap = 2.0;
        $w   = 1000.0;                       // repère interne, la bande s'étire
        $bw  = max(1.0, ($w - ($n - 1) * $gap) / $n);
        $svg = '<svg class="pulse" viewBox="0 0 ' . (int)$w . ' ' . $h . '"'
             . ' preserveAspectRatio="none" role="img" aria-label="'
             . e(t('Les {n} dernières tranches de temps, tous sites confondus', ['n' => $n])) . '">';
        $x = 0.0;
        foreach ($buckets as $b) {
            $state = (string)($b['state'] ?? 'none');
            // Une tranche sans mesure reste visible mais discrète : un trou dans
            // la surveillance est une information, pas un vide à masquer.
            $cls = 'pl-' . ($state === 'none' ? 'none' : $state);
            $hh  = $state === 'none' ? max(4, (int)round($h * .35)) : $h;
            $y   = (int)round(($h - $hh) / 2);
            $svg .= '<rect class="' . $cls . '" x="' . round($x, 2) . '" y="' . $y
                  . '" width="' . round($bw, 2) . '" height="' . $hh . '" rx="1.5">'
                  . '<title>' . e(self::pulseTitle($b)) . '</title></rect>';
            $x += $bw + $gap;
        }
        return $svg . '</svg>';
    }

    private static function pulseTitle(array $b): string
    {
        $when = isset($b['t']) ? date('H:i', (int)$b['t']) : '';
        $state = (string)($b['state'] ?? 'none');
        if ($state === 'none') return $when . ' · ' . t('aucune mesure');
        $bits = [$when];
        if (!empty($b['down']))     $bits[] = tn((int)$b['down'], 'un site hors service', '{n} sites hors service');
        elseif (!empty($b['degraded'])) $bits[] = tn((int)$b['degraded'], 'un site à surveiller', '{n} sites à surveiller');
        else $bits[] = t('tout répondait');
        if (isset($b['avg_ms']) && $b['avg_ms'] !== null) $bits[] = self::ms((int)$b['avg_ms']);
        return implode(' · ', $bits);
    }

    private static function bucketTitle(array $b): string
    {
        $when = isset($b['t']) ? date('d/m H:i', (int)$b['t']) : '';
        if (($b['state'] ?? 'none') === 'none') return $when . ' · ' . t('aucune mesure');
        $bits = [$when];
        if (isset($b['avg_ms']) && $b['avg_ms'] !== null) $bits[] = self::ms((int)$b['avg_ms']);
        if (!empty($b['fails']))    $bits[] = tn((int)$b['fails'], 'un échec', '{n} échecs');
        if (!empty($b['down_sec'])) $bits[] = t('hors service {duration}',
                                                  ['duration' => human_duration((int)$b['down_sec'])]);
        if (!empty($b['degraded'])) $bits[] = tn((int)$b['degraded'], 'un dégradé', '{n} dégradés');
        return implode(' · ', $bits);
    }

    /** Graphique détaillé : bandes de panne + courbe de temps de réponse. */
    public static function chart(array $series, int $w = 1000, int $h = 240): string
    {
        $buckets = $series['buckets'] ?? [];
        $n = count($buckets);
        if ($n === 0) {
            return '<div class="chart-empty">' . e(t('Aucune donnée sur cette période.')) . '<br>'
                 . '<span class="small">' . e(t('Lancez une vérification, ou attendez la prochaine passe de la tâche planifiée.')) . '</span></div>';
        }

        $padL = 44; $padR = 10; $padT = 10; $padB = 26;
        $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;

        $vals = array_values(array_filter(array_map(fn($b) => (int)($b['avg_ms'] ?? 0), $buckets)));
        sort($vals);
        $max = $vals ? ($vals[(int)floor(count($vals) * 0.95)] ?? end($vals)) : 100;
        $max = (int)max(100, ceil($max * 1.25 / 50) * 50);

        $x = fn(int $i) => $padL + ($n > 1 ? ($i / ($n - 1)) * $iw : $iw / 2);
        $y = fn(float $ms) => $padT + $ih - min(1, $ms / $max) * $ih;

        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img"'
             . ' aria-label="' . e(t('Temps de réponse et périodes d\'indisponibilité')) . '">';

        for ($g = 0; $g <= 4; $g++) {
            $vy = $padT + ($ih / 4) * $g;
            $svg .= '<line class="grid" x1="' . $padL . '" y1="' . $vy . '" x2="' . ($w - $padR) . '" y2="' . $vy . '"/>'
                 . '<text class="axis" x="' . ($padL - 7) . '" y="' . ($vy + 4) . '" text-anchor="end">'
                 . (int)round($max - ($max / 4) * $g) . '</text>';
        }

        $bw = $n > 1 ? $iw / ($n - 1) : $iw;
        foreach ($buckets as $i => $b) {
            $cls = null;
            if (($b['state'] ?? '') === 'down' || (int)($b['down_sec'] ?? 0) > 0) $cls = 'downband';
            elseif (($b['state'] ?? '') === 'degraded') $cls = 'warnband';
            if ($cls) {
                $svg .= '<rect class="' . $cls . '" x="' . round($x($i) - $bw / 2, 2) . '" y="' . $padT
                     . '" width="' . round(max(1.5, $bw), 2) . '" height="' . $ih . '">'
                     . '<title>' . e(self::bucketTitle($b)) . '</title></rect>';
            }
        }

        $pts = [];
        foreach ($buckets as $i => $b) {
            $ms = $b['avg_ms'] ?? null;
            if ($ms === null) continue;
            $pts[] = round($x($i), 2) . ',' . round($y((float)$ms), 2);
        }
        if (count($pts) > 1) {
            $first = explode(',', $pts[0])[0];
            $last  = explode(',', $pts[count($pts) - 1])[0];
            $svg .= '<polygon class="area" points="' . $first . ',' . ($padT + $ih) . ' '
                 . implode(' ', $pts) . ' ' . $last . ',' . ($padT + $ih) . '"/>'
                 . '<polyline class="line" points="' . implode(' ', $pts) . '"/>';
        } elseif (count($pts) === 1) {
            [$px, $py] = explode(',', $pts[0]);
            $svg .= '<circle class="pt" cx="' . $px . '" cy="' . $py . '" r="3"/>';
        }

        $ticks = min(6, max(2, (int)floor($n / 8)));
        $span  = ($series['step'] ?? 300) * $n;
        $fmt   = $span > 45 * 86400 ? 'M y' : ($span > 3 * 86400 ? 'd/m' : ($span > 86400 ? 'd/m H\hi' : 'H:i'));
        for ($t = 0; $t <= $ticks; $t++) {
            $i = (int)round(($n - 1) * ($t / $ticks));
            $b = $buckets[$i] ?? null;
            if (!$b) continue;
            $anchor = $t === 0 ? 'start' : ($t === $ticks ? 'end' : 'middle');
            $svg .= '<text class="axis" x="' . round($x($i), 2) . '" y="' . ($h - 7) . '" text-anchor="' . $anchor . '">'
                 . e(self::shortDate($fmt, (int)$b['t'])) . '</text>';
        }
        return $svg . '</svg>';
    }

    /**
     * Dates courtes, mois abrégé traduit.
     *
     * date() ne connaît que l'anglais : les douze abréviations sont donc des
     * msgid comme le reste de l'interface. En anglais, le catalogue rend « Jan »
     * plutôt que « janv. ».
     */
    private static function shortDate(string $fmt, int $ts): string
    {
        if ($fmt !== 'M y') return date($fmt, $ts);
        static $keys = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
                        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        return t($keys[(int)date('n', $ts)]) . ' ' . date('y', $ts);
    }

    /** Frise de disponibilité, une case par jour. */
    public static function dayStrip(int $monitorId, int $days = 30): string
    {
        $rows = Db::all('SELECT day, checks, fails, downtime_sec FROM daily_stats
                         WHERE monitor_id = ? AND day >= ? ORDER BY day ASC',
            [$monitorId, date('Y-m-d', time() - $days * 86400)]);
        $byDay = [];
        foreach ($rows as $r) $byDay[(string)$r['day']] = $r;

        $out = '<div class="daystrip" role="img" aria-label="'
             . e(tn($days, 'Disponibilité du dernier jour', 'Disponibilité des {n} derniers jours')) . '">';
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', time() - $i * 86400);
            $r   = $byDay[$day] ?? null;
            if (!$r) { $cls = 'none'; $title = date('d/m', strtotime($day)) . ' · ' . t('pas de donnée'); }
            else {
                $down = (int)$r['downtime_sec'];
                $cls  = $down > 900 ? 'down' : ($down > 0 || (int)$r['fails'] > 0 ? 'degraded' : 'up');
                $title = date('d/m', strtotime($day)) . ' · '
                       . ($down > 0 ? t('HS {duration}', ['duration' => human_duration($down)])
                                    : t('100 % en ligne'));
            }
            $out .= '<i class="d-' . $cls . '" title="' . e($title) . '"></i>';
        }
        return $out . '</div>';
    }

    public static function reasonBadge(?string $code): string
    {
        if (!$code) return '';
        $tone = in_array($code, ['SLOW', 'SSL_SOON', 'NOINDEX', 'CSS_DEGRADED', 'ASSET_DEGRADED'], true) ? 'warn' : 'bad';
        return self::badge(Notify\Notifier::reasonLabel($code), $tone);
    }

    // =====================================================================
    // Périodes
    // =====================================================================

    public const RANGES = [
        '1h'   => '1 h',
        '24h'  => '24 h',
        '7d'   => '7 j',
        '30d'  => '30 j',
        '90d'  => '90 j',
        '120d' => '4 mois',
        '180d' => '6 mois',
        '365d' => '1 an',
    ];

    /**
     * Une page autonome pour un refus attendu, montrée à un visiteur non connecté.
     *
     * CE QUE VOIT QUELQU'UN QUI SUIT UN VIEUX LIEN. La page de statut désactivée et un
     * lien d'espace client expiré rendaient du TEXTE NU : « Page de statut non activée. »
     * seul sur fond blanc, sans mise en forme, sans titre d'onglet, sans même dire de quel
     * service il s'agit. C'est ce que voit le client d'un client, et il en conclut
     * raisonnablement que le site est cassé.
     *
     * Ce n'est pas une panne, c'est une réponse : un lien qui n'est plus valable. Elle
     * mérite donc une page, courte, qui dit ce qui se passe sans en dire trop.
     *
     * ON RESTE VOLONTAIREMENT VAGUE, et c'est déjà la règle du code appelant : un jeton
     * inconnu, mal formé ou révoqué reçoivent le même message. Distinguer les trois
     * permettrait de deviner lesquels ont existé.
     */
    public static function pagePublique(string $titre, string $texte, int $code = 404): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        // Un lien mort ne doit pas se retrouver indexé, ni faire fuir le jeton par référent.
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');

        $lang = 'en';
        $dir = 'ltr';
        try { $lang = I18n::lang(); $dir = I18n::dir(); } catch (\Throwable) {}

        echo '<!doctype html><html lang="' . e($lang) . '" dir="' . e($dir) . '">'
           . '<head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex,nofollow">'
           . '<title>' . e($titre) . '</title></head>'
           . '<body style="margin:0;font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
           . 'background:#f6f7f9;color:#1f2430">'
           . '<div style="max-width:30rem;margin:16vh auto;padding:0 1.25rem;text-align:center">'
           . '<h1 style="font-size:1.35rem;margin:0 0 .5rem">' . e($titre) . '</h1>'
           . '<p style="margin:0;color:#5b6472">' . e($texte) . '</p>'
           . '</div></body></html>';
    }

    /**
     * Combien de lignes par page, et pourquoi il en faut une limite.
     *
     * MESURÉ LE 2026-08-02 SUR UNE INSTANCE RÉELLE : l'écran des sondes faisait 12 835 px
     * de haut pour 200 lignes, le journal 14 685 px pour 320, les incidents 12 144 px pour
     * 175. Aucune pagination. Ce n'est pas qu'une question de confort : une page de treize
     * mille pixels ne se parcourt pas, elle se subit, et la ligne rouge qui compte se perd
     * dans neuf écrans de lignes vertes.
     *
     * Cinquante lignes tiennent sous le plafond de 4 000 px qu'on s'est fixé, tout en
     * remplissant un écran de bureau : moins ferait tourner les pages pour rien.
     *
     * MAIS PAS SUR TOUS LES ÉCRANS, ET SEULE LA MESURE POUVAIT LE DIRE. Après la première
     * pose, la mesure au navigateur sur l'instance réelle a rendu : sondes 3 551 px,
     * journal 4 892 px, incidents 4 072 px. Deux dépassements, pour deux raisons
     * différentes qu'aucun raisonnement n'aurait données d'avance.
     *
     * Le journal affiche DEUX tableaux, donc cinquante lignes par page en font cent à
     * l'écran. Les incidents portent un bandeau de quatre statistiques, des filtres, et
     * depuis aujourd'hui un panneau de retour par ligne. D'où deux valeurs, chacune
     * justifiée par ce qui a été mesuré et non par une préférence.
     */
    public const PAR_PAGE = 50;

    /** Journal : deux tableaux sur la même page, donc moitié moins par tableau. */
    public const PAR_PAGE_DOUBLE = 25;

    /** Incidents : bandeau de statistiques, filtres, et un panneau replié par ligne. */
    public const PAR_PAGE_DENSE = 40;

    /**
     * La page demandée, bornée.
     *
     * Une page négative ou absurde ne doit pas produire un OFFSET négatif, que SQLite
     * accepte en silence et que MySQL refuse : le même lien casserait sur un moteur et pas
     * sur l'autre, ce qui est la pire forme de défaut.
     */
    public static function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    /** Le décalage SQL correspondant, pour ne pas le recalculer dans chaque vue. */
    public static function saut(int $page, int $parPage = self::PAR_PAGE): int
    {
        return max(0, ($page - 1) * $parPage);
    }

    /**
     * Les liens de pagination, ou rien du tout s'il n'y a qu'une page.
     *
     * ON AFFICHE LE TOTAL, PAS SEULEMENT LES FLÈCHES. « 1 à 50 sur 200 » dit à la fois où
     * l'on est et ce qu'on ne voit pas ; des flèches seules laissent croire qu'on a tout lu
     * quand on a lu le quart.
     *
     * @param array<string,mixed> $params paramètres à conserver dans les liens
     */
    public static function pagination(int $page, int $total, string $vue, array $params = [],
                                      int $parPage = self::PAR_PAGE): string
    {
        $pages = (int) ceil($total / max(1, $parPage));

        if ($pages <= 1) {
            return '';
        }

        $lien = static function (int $p, string $texte) use ($vue, $params): string {
            return '<a class="btn btn-sm btn-ghost" href="'
                . e(u($vue, $params + ['page' => $p > 1 ? $p : null])) . '">' . $texte . '</a>';
        };

        $premier = (($page - 1) * $parPage) + 1;
        $dernier = min($page * $parPage, $total);

        $out = '<div class="row-between mt" style="align-items:center">';
        $out .= '<span class="tiny muted">' . e(t('{de} à {a} sur {total}',
            ['de' => $premier, 'a' => $dernier, 'total' => $total])) . '</span>';
        $out .= '<div class="row" style="gap:6px">';
        $out .= $page > 1 ? $lien($page - 1, te('Précédentes')) : '';
        $out .= '<span class="tiny muted">' . e(t('page {n} sur {total}',
            ['n' => $page, 'total' => $pages])) . '</span>';
        $out .= $page < $pages ? $lien($page + 1, te('Suivantes')) : '';
        $out .= '</div></div>';

        return $out;
    }

    public static function rangePicker(string $current, array $params = []): string
    {
        $out = '<div class="segmented" role="tablist" aria-label="' . te('Période affichée') . '">';
        foreach (self::RANGES as $k => $label) {
            $p = $params; $p['range'] = $k;
            $on = $k === $current;
            $out .= '<a role="tab" aria-selected="' . ($on ? 'true' : 'false') . '"'
                 . ' href="' . e(u($p['p'] ?? 'monitor', $p)) . '">' . e($label) . '</a>';
        }
        return $out . '</div>';
    }

    public static function rangeSeconds(string $range): int
    {
        return match ($range) {
            '1h'   => 3600,
            '6h'   => 21600,
            '7d'   => 604800,
            '30d'  => 2592000,
            '90d'  => 7776000,
            '120d' => 10368000,
            '180d' => 15552000,
            '365d' => 31536000,
            default => 86400,
        };
    }

    public static function rangeBuckets(string $range): int
    {
        return match ($range) {
            '1h' => 60, '6h' => 72, '7d' => 84,
            '30d' => 90, '90d' => 90, '120d' => 120, '180d' => 90, '365d' => 73,
            default => 96,
        };
    }

    public static function rangeLabel(string $range): string
    {
        return self::RANGES[$range] ?? '24 h';
    }
}
