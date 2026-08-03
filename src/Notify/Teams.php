<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Http;
use Uptimeez\I18n;

/**
 * Microsoft Teams, par une URL entrante.
 *
 * ------------------------------------------------------------------------------
 * DEUX GÉNÉRATIONS D'URL COEXISTENT, ET LA CHARGE UTILE TIENT COMPTE DES DEUX
 * ------------------------------------------------------------------------------
 *
 * Teams a longtemps utilisé les « connecteurs Office 365 », dont l'URL accepte une carte au
 * format MessageCard. Microsoft les retire au profit des flux Power Automate, dont l'URL
 * accepte un JSON quelconque que le flux mappe lui-même, et dont les modèles les plus
 * répandus lisent un champ « text ».
 *
 * On envoie donc les deux dans le même objet : la structure MessageCard pour un connecteur
 * classique, et un champ « text » à plat pour un flux. Ce n'est pas de l'indécision, c'est
 * la seule charge utile qui fonctionne sans demander à l'exploitant de quelle génération
 * est son URL, question à laquelle il ne peut souvent pas répondre.
 *
 * CE QUI RESTE À LA CHARGE DE L'EXPLOITANT, ET LA PAGE DES RÉGLAGES LE DIT : un flux Power
 * Automate n'affiche rien tant que son modèle n'est pas relié à un champ. Le bouton de test
 * y répond « HTTP 202 » sans qu'aucun message n'apparaisse, ce qui est exact du point de vue
 * du transport et trompeur du point de vue de l'usage. Le test dit donc « accepté » et non
 * « reçu ».
 */
final class Teams
{
    /**
     * @param array<int,array{0:string,1:string}> $lines
     * @param array<string,mixed> $mon
     * @return array{ok:bool,info:string}
     */
    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        if ($q = \Uptimeez\Demo::silenced()) {
            return $q;
        }

        $url = trim((string) Config::get('notify.teams.webhook', ''));

        if ($url === '') {
            return ['ok' => false, 'info' => t('URL entrante Teams non configurée')];
        }

        $faits = [];
        $plat = $title;

        foreach ($lines as [$nom, $valeur]) {
            $valeur = (string) $valeur;

            if ($valeur === '') {
                continue;
            }

            $faits[] = ['name' => $nom, 'value' => str_cut($valeur, 900)];
            $plat .= "\n" . $nom . ' : ' . $valeur;
        }

        $lien = Notifier::monitorLink($mon);

        if ($lien !== null) {
            $plat .= "\n" . $lien;
        }

        // La couleur de thème d'une MessageCard est une chaîne hexadécimale sans dièse, là
        // où Discord attend un entier : la même constante ne peut pas servir aux deux.
        $couleur = match ($sev) {
            'down' => 'C0392B',
            'degraded' => 'E67E22',
            default => '27AE60',
        };

        $charge = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'themeColor' => $couleur,
            'summary' => str_cut($title, 240),
            'title' => str_cut($title, 240),
            // Pour un flux Power Automate, qui lit un champ à plat.
            'text' => str_cut($plat, 3000),
            'sections' => [['facts' => $faits, 'markdown' => false]],
        ];

        if ($lien !== null) {
            $charge['potentialAction'] = [[
                '@type' => 'OpenUri',
                'name' => t('Ouvrir la fiche de surveillance'),
                'targets' => [['os' => 'default', 'uri' => $lien]],
            ]];
        }

        $res = Http::fetch($url, [
            'method'  => 'POST',
            'body'    => jenc($charge),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 12,
            'maxBody' => 20000,
        ]);

        // 202 est la réponse normale d'un flux Power Automate : il a pris la demande, il
        // n'a rien affiché encore. On l'accepte, et le libellé le dit.
        $ok = $res->ok && $res->status >= 200 && $res->status < 300;

        return ['ok' => $ok, 'info' => $ok
            ? 'HTTP ' . $res->status . ($res->status === 202 ? ' ' . t('(accepté par le flux)') : '')
            : 'HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string) $res->error, 200)];
    }
}
