<?php
/**
 * Uptimer : traduction de l'interface.
 *
 * Choix d'architecture : la clé de traduction est la phrase française du code
 * source (msgid), comme le fait gettext. Deux avantages concrets :
 *   1. aucun code obscur du type « nav.today.label » dans les gabarits ;
 *   2. une chaîne oubliée reste lisible au lieu d'afficher sa clé.
 *
 * L'anglais est la langue par défaut : le catalogue lang/en.php est donc
 * obligatoire et sa complétude est vérifiée par bin/i18n-audit.php.
 */
declare(strict_types=1);

namespace Uptimer;

final class I18n
{
    /**
     * Les dix langues les plus parlées au monde (nombre total de locuteurs).
     * code => [nom anglais, nom dans la langue, sens d'écriture, étiquette courte, drapeau]
     *
     * Le drapeau est une aide au repérage, pas une vérité : une langue n'est pas
     * un pays. L'espagnol se parle surtout hors d'Espagne, l'arabe dans vingt
     * pays, l'anglais partout. On retient donc le drapeau le plus reconnaissable
     * pour trouver sa ligne vite, et le nom de la langue reste ce qui compte.
     *
     * C'est la seule exception assumée à la règle « aucun emoji dans
     * l'interface » : ici l'emoji identifie plus vite que n'importe quelle icône.
     */
    public const LANGS = [
        'en' => ['English',    'English',   'ltr', 'EN',   '🇬🇧'],
        'zh' => ['Chinese',    '中文',       'ltr', '中文', '🇨🇳'],
        'hi' => ['Hindi',      'हिन्दी',      'ltr', 'हिन्दी', '🇮🇳'],
        'es' => ['Spanish',    'Español',   'ltr', 'ES',   '🇪🇸'],
        'ar' => ['Arabic',     'العربية',    'rtl', 'ع',    '🇸🇦'],
        'fr' => ['French',     'Français',  'ltr', 'FR',   '🇫🇷'],
        'bn' => ['Bengali',    'বাংলা',      'ltr', 'বাং',  '🇧🇩'],
        'pt' => ['Portuguese', 'Português', 'ltr', 'PT',   '🇵🇹'],
        'ru' => ['Russian',    'Русский',   'ltr', 'RU',   '🇷🇺'],
        'ur' => ['Urdu',       'اردو',       'rtl', 'اردو', '🇵🇰'],
    ];

    public const DEFAULT = 'en';

    /**
     * Nom du produit. Il n'apparaît JAMAIS dans une clé de traduction : les
     * phrases écrivent « {app} », substitué ici. Un renommage du produit ne
     * périme donc aucun catalogue : la leçon de deux renommages successifs.
     */
    public const APP = 'Uptimer';

    /** Le français est la langue des msgid : son catalogue est l'identité. */
    public const SOURCE = 'fr';

    private static string $lang = self::DEFAULT;
    private static array $cat = [];
    private static bool $ready = false;

    /** Chaînes demandées mais absentes du catalogue (diagnostic, pas d'erreur). */
    private static array $missing = [];

    /**
     * Chaîne de repli : langue demandée → anglais → texte source français.
     *
     * L'anglais est intercalé parce qu'il est la langue par défaut du produit :
     * une phrase non encore traduite en hindi doit s'afficher en anglais, pas
     * en français.
     */
    public static function init(?string $forced = null): void
    {
        self::$lang = self::normalize($forced ?? self::negotiate());
        if (self::$lang === self::SOURCE) {
            self::$cat = [];
        } elseif (self::$lang === self::DEFAULT) {
            self::$cat = self::load(self::DEFAULT);
        } else {
            // La langue demandée gagne, l'anglais comble les trous.
            self::$cat = self::load(self::$lang) + self::load(self::DEFAULT);
        }
        self::$ready = true;
    }

    /** Langue effective. */
    public static function lang(): string
    {
        if (!self::$ready) self::init();
        return self::$lang;
    }

    /** « ltr » ou « rtl » : l'arabe et l'ourdou s'écrivent de droite à gauche. */
    public static function dir(?string $lang = null): string
    {
        return self::LANGS[$lang ?? self::lang()][2] ?? 'ltr';
    }

    public static function name(string $lang): string
    {
        return self::LANGS[$lang][1] ?? $lang;
    }

    /**
     * Traduit et substitue les variables : t('{n} sites', ['n' => 3]).
     * Les valeurs sont insérées telles quelles : c'est à l'appelant d'échapper.
     */
    public static function t(string $msgid, array $vars = []): string
    {
        if (!self::$ready) self::init();
        $out = self::$cat[$msgid] ?? null;
        if ($out === null || $out === '') {
            if (self::$lang !== self::SOURCE) self::$missing[$msgid] = true;
            $out = $msgid;
        }
        if ($vars) {
            $keys = array_map(static fn ($k) => '{' . $k . '}', array_keys($vars));
            $out  = str_replace($keys, array_map('strval', array_values($vars)), $out);
        }
        // Le nom du produit est toujours disponible, sans que l'appelant le passe.
        return str_contains($out, '{app}') ? str_replace('{app}', self::APP, $out) : $out;
    }

    /**
     * Pluriel. Les deux phrases sont des msgid ordinaires, traduits séparément :
     * l'extracteur les voit, et une seule des deux peut manquer sans casser
     * l'autre.
     *
     * Les langues à plus de deux formes (russe, arabe) mettent les formes
     * supplémentaires dans la traduction du pluriel, séparées par « | » :
     *     '{n} échecs' => '{n} ошибки|{n} ошибок'
     * ce qui donne trois formes en tout avec le singulier.
     */
    public static function n(int $count, string $one, string $many, array $vars = []): string
    {
        $vars['n'] = $count;
        $forms = array_merge([self::t($one)], explode('|', self::t($many)));
        $i = self::plural($count, count($forms));
        return self::interpolate($forms[$i] ?? $forms[count($forms) - 1], $vars);
    }

    private static function interpolate(string $s, array $vars): string
    {
        $keys = array_map(static fn ($k) => '{' . $k . '}', array_keys($vars));
        $out  = str_replace($keys, array_map('strval', array_values($vars)), $s);
        return str_replace('{app}', self::APP, $out);
    }

    /** Index de forme plurielle, selon la famille de langue. */
    private static function plural(int $n, int $forms): int
    {
        $l = self::lang();
        // Langues sans variation : une seule forme suffit.
        if (in_array($l, ['zh'], true)) return 0;
        if ($forms <= 2) {
            // Le français met au singulier 0 et 1 ; l'anglais seulement 1.
            if ($l === 'fr') return $n > 1 ? 1 : 0;
            return $n === 1 ? 0 : 1;
        }
        if ($l === 'ru') {
            $m10 = $n % 10; $m100 = $n % 100;
            if ($m10 === 1 && $m100 !== 11) return 0;
            if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) return 1;
            return 2;
        }
        if ($l === 'ar') {
            if ($n === 1) return 0;
            if ($n === 2) return 1;
            return 2;
        }
        return $n === 1 ? 0 : min(1, $forms - 1);
    }

    /** Liste des langues proposées : code => nom dans la langue. */
    public static function available(): array
    {
        $out = [];
        foreach (self::LANGS as $code => $meta) {
            $out[$code] = $meta[1];
        }
        return $out;
    }

    /** Drapeau d'aide au repérage. Voir la note sur LANGS. */
    public static function flag(string $lang): string
    {
        return self::LANGS[$lang][4] ?? '';
    }

    public static function missing(): array
    {
        return array_keys(self::$missing);
    }

    public static function normalize(?string $lang): string
    {
        $lang = strtolower(substr(trim((string)$lang), 0, 5));
        $lang = str_replace('_', '-', $lang);
        if (isset(self::LANGS[$lang])) return $lang;
        $base = explode('-', $lang)[0];
        if (isset(self::LANGS[$base])) return $base;
        // Variantes courantes des langues chinoises et arabes.
        static $alias = ['zh-cn' => 'zh', 'zh-tw' => 'zh', 'zh-hans' => 'zh', 'zh-hant' => 'zh',
                         'pt-br' => 'pt', 'pt-pt' => 'pt', 'en-us' => 'en', 'en-gb' => 'en',
                         'fr-fr' => 'fr', 'fr-be' => 'fr', 'fr-ca' => 'fr', 'es-mx' => 'es',
                         'es-es' => 'es', 'in' => 'hi', 'iw' => 'en', 'nb' => 'en'];
        return $alias[$lang] ?? self::DEFAULT;
    }

    /**
     * Détermine la langue sans jamais poser de question : URL, choix mémorisé,
     * réglage de l'instance, puis en-tête du navigateur.
     */
    public static function negotiate(): string
    {
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            $l = self::normalize($_GET['lang']);
            self::remember($l);
            return $l;
        }
        if (!empty($_SESSION['uptimer_lang'])) return self::normalize((string)$_SESSION['uptimer_lang']);
        if (!empty($_COOKIE['uptimer_lang']))  return self::normalize((string)$_COOKIE['uptimer_lang']);

        $configured = (string)Config::get('app.locale', '');
        if ($configured !== '' && $configured !== 'auto') return self::normalize($configured);

        return self::fromHeader((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }

    /** Choisit la meilleure langue d'un en-tête Accept-Language. */
    public static function fromHeader(string $header): string
    {
        if (trim($header) === '') return self::DEFAULT;
        $best = self::DEFAULT; $bestQ = -1.0;
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag  = strtolower(trim($bits[0]));
            if ($tag === '' || $tag === '*') continue;
            $q = 1.0;
            foreach (array_slice($bits, 1) as $p) {
                if (preg_match('~q\s*=\s*([0-9.]+)~', $p, $m)) $q = (float)$m[1];
            }
            $norm = self::normalize($tag);
            // normalize() retombe sur l'anglais : on ne retient que les vraies
            // correspondances, sinon « de-DE » gagnerait la mise avec q=1.
            $known = isset(self::LANGS[$tag]) || isset(self::LANGS[explode('-', $tag)[0]]);
            if ($known && $q > $bestQ) { $best = $norm; $bestQ = $q; }
        }
        return $best;
    }

    public static function remember(string $lang): void
    {
        $lang = self::normalize($lang);
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['uptimer_lang'] = $lang;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('uptimer_lang', $lang, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['uptimer_lang'] = $lang;
    }

    private static function load(string $lang): array
    {
        $file = \UPTIMER_ROOT . '/lang/' . $lang . '.php';
        if (!is_file($file)) return [];
        $cat = require $file;
        return is_array($cat) ? $cat : [];
    }

    /** Utilisé par l'audit : catalogue brut d'une langue. */
    public static function catalogue(string $lang): array
    {
        return self::load(self::normalize($lang));
    }
}
