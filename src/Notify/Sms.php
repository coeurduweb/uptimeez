<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Http;

/**
 * SMS, par Twilio.
 *
 * ------------------------------------------------------------------------------
 * LE SEUL CANAL QUI COÛTE DE L'ARGENT À CHAQUE ENVOI
 * ------------------------------------------------------------------------------
 *
 * Tous les autres canaux sont gratuits et illimités : on peut y répéter une alerte sans y
 * penser. Un SMS se paie à l'unité, donc deux décisions changent par rapport aux autres.
 *
 * PREMIÈREMENT, LE MESSAGE EST COURT PAR CONSTRUCTION. Un SMS de plus de 160 caractères est
 * facturé en plusieurs segments, et un rapport complet n'a de toute façon aucun intérêt sur
 * un écran de verrouillage : ce qu'on veut, c'est le nom du site et la nature du problème,
 * pour décider d'ouvrir son ordinateur ou de se rendormir. On envoie donc le titre et la
 * première ligne, tronqués à 155 caractères.
 *
 * DEUXIÈMEMENT, IL EST DESTINÉ À L'ESCALADE. Rien ne l'y oblige techniquement, mais c'est
 * son usage : le laisser dans les canaux d'alerte ordinaires facture un SMS pour chaque
 * certificat qui expire dans quinze jours. La page des réglages le dit.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI TWILIO, ET COMMENT PARTIR
 * ------------------------------------------------------------------------------
 *
 * Parce que son API tient en une requête POST authentifiée en Basic, sans bibliothèque, ce
 * qui est la seule condition qui compte dans ce moteur. Toute passerelle qui accepte la
 * même forme se branche en changeant l'URL ; celles qui exigent un SDK sont hors sujet ici,
 * et le webhook générique reste la porte de sortie pour elles.
 */
final class Sms
{
    /** Un SMS est facturé par segment de 160 caractères : on tient dans un seul. */
    private const MAX = 155;

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

        $sid = trim((string) Config::get('notify.sms.sid', ''));
        $jeton = trim((string) Config::get('notify.sms.token', ''));
        $de = trim((string) Config::get('notify.sms.from', ''));
        $vers = trim((string) Config::get('notify.sms.to', ''));

        if ($sid === '' || $jeton === '' || $de === '' || $vers === '') {
            return ['ok' => false, 'info' => t('Identifiants ou numéros SMS manquants')];
        }

        // Le titre porte l'émoji et le nom du site ; la première ligne porte la cause.
        // Au-delà, on paierait un second segment pour du contexte qu'on lira sur l'écran.
        $texte = $title;

        if (isset($lines[0][1]) && (string) $lines[0][1] !== '') {
            $texte .= ' — ' . (string) $lines[0][1];
        }

        $envois = [];

        // PLUSIEURS DESTINATAIRES, UN ENVOI CHACUN : l'API n'accepte qu'un numéro par
        // requête. Le compte-rendu dit combien sont partis, parce qu'un seul numéro
        // injoignable sur trois ne doit pas passer pour un échec complet.
        foreach (array_filter(array_map('trim', explode(',', $vers))) as $numero) {
            $res = Http::fetch('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', [
                'method'  => 'POST',
                'body'    => http_build_query([
                    'From' => $de,
                    'To'   => $numero,
                    'Body' => str_cut($texte, self::MAX),
                ]),
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($sid . ':' . $jeton),
                ],
                'timeout' => 15,
                'maxBody' => 20000,
            ]);

            $envois[$numero] = $res->ok && $res->status >= 200 && $res->status < 300
                ? true
                : 'HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string) $res->error, 120);
        }

        $partis = count(array_filter($envois, static fn (mixed $v): bool => $v === true));
        $echecs = array_filter($envois, static fn (mixed $v): bool => $v !== true);

        return [
            'ok' => $partis > 0,
            'info' => $partis . '/' . count($envois) . ' ' . t('envoyé(s)')
                . ($echecs === [] ? '' : ' · ' . str_cut(implode(' ; ', array_map(
                    static fn (string $n, mixed $e): string => $n . ' ' . (string) $e,
                    array_keys($echecs),
                    $echecs
                )), 200)),
        ];
    }
}
