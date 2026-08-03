<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Http;

/**
 * Telegram, par l'API des robots.
 *
 * ------------------------------------------------------------------------------
 * DEUX RÉGLAGES ET NON UN, ET C'EST LA PREMIÈRE CHOSE QUI SURPREND
 * ------------------------------------------------------------------------------
 *
 * Discord et Slack donnent une URL de webhook qui contient tout. Telegram non : il faut le
 * jeton du robot, obtenu auprès de @BotFather, ET l'identifiant de la conversation, qui
 * n'est écrit nulle part dans l'interface. Le plus simple pour l'obtenir : écrire un
 * message au robot, puis lire l'identifiant dans la réponse de
 * api.telegram.org/bot{jeton}/getUpdates. La page des réglages le dit, parce que ne pas le
 * dire transforme une configuration de deux minutes en une demi-heure de recherche.
 *
 * ------------------------------------------------------------------------------
 * DU TEXTE BRUT, PAS DE MISE EN FORME
 * ------------------------------------------------------------------------------
 *
 * Telegram accepte du HTML ou du Markdown, et refuse le message entier quand un caractère
 * y est mal échappé. Or les alertes contiennent des extraits de pages : du HTML, des accents
 * grecs, des guillemets, tout ce qui casse un analyseur. Une alerte qui n'arrive pas parce
 * qu'un souligné était mal placé est le pire des deux mondes, donc on n'envoie aucune mise
 * en forme. Le titre porte déjà son émoji, ce qui suffit à distinguer une panne d'un
 * rétablissement dans une liste de conversations.
 */
final class Telegram
{
    /** Telegram coupe à 4096 caractères : on tronque avant lui, pour garder la fin utile. */
    private const MAX = 3800;

    /**
     * @param array<int,array{0:string,1:string}> $lines
     * @param array<string,mixed> $mon
     * @return array{ok:bool,info:string}
     */
    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        // Démonstration publique : rien ne sort. Le verrou est dans l'expéditeur et pas
        // seulement dans la configuration, parce qu'un visiteur atteint l'écran des réglages.
        if ($q = \Uptimeez\Demo::silenced()) {
            return $q;
        }

        $jeton = trim((string) Config::get('notify.telegram.token', ''));
        $salon = trim((string) Config::get('notify.telegram.chat_id', ''));

        if ($jeton === '' || $salon === '') {
            return ['ok' => false, 'info' => t('Jeton ou identifiant de conversation Telegram manquant')];
        }

        $texte = $title;

        foreach ($lines as [$nom, $valeur]) {
            $valeur = (string) $valeur;

            if ($valeur === '') {
                continue;
            }

            $texte .= "\n" . $nom . ' : ' . $valeur;
        }

        if ($lien = Notifier::monitorLink($mon)) {
            $texte .= "\n" . $lien;
        }

        $res = Http::fetch('https://api.telegram.org/bot' . rawurlencode($jeton) . '/sendMessage', [
            'method'  => 'POST',
            'body'    => http_build_query([
                'chat_id' => $salon,
                'text'    => str_cut($texte, self::MAX),
                // Les aperçus de lien transforment une alerte en vignette du site en panne,
                // ce qui est à la fois inutile et lent à charger.
                'disable_web_page_preview' => 'true',
            ]),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'timeout' => 12,
            'maxBody' => 20000,
        ]);

        $ok = $res->ok && $res->status >= 200 && $res->status < 300;

        // LE CORPS EST LU MÊME EN CAS DE SUCCÈS APPARENT. Telegram répond 200 avec
        // « ok: false » quand le salon est inconnu, ce qui ferait croire à un envoi réussi.
        if ($ok) {
            $json = json_decode((string) $res->body, true);
            $ok = is_array($json) && ($json['ok'] ?? false) === true;

            if (! $ok) {
                return ['ok' => false, 'info' => t('HTTP 200 mais refus : {raison}',
                    ['raison' => str_cut((string) ($json['description'] ?? $res->body), 200)])];
            }
        }

        return ['ok' => $ok, 'info' => $ok
            ? 'HTTP ' . $res->status
            : 'HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string) $res->error, 200)];
    }
}
