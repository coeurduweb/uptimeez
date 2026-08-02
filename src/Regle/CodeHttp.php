<?php

namespace Uptimeez\Regle;

use Uptimeez\Runner;

/**
 * Le serveur a-t-il répondu avec le code qu'on attendait ?
 *
 * ------------------------------------------------------------------------------
 * POURQUOI HUIT CAUSES ET NON UNE SEULE « MAUVAIS CODE HTTP »
 * ------------------------------------------------------------------------------
 *
 * Un 404, un 403 et un 500 ne se corrigent ni par la même personne ni au même endroit :
 * une page supprimée, un droit d'accès, une application qui plante. Les fondre en une
 * cause unique obligerait à rouvrir l'alerte pour lire le code, et surtout rendrait
 * impossible de compter les pannes par nature, qui est ce que le rapport mensuel montre.
 *
 * Le 429 mérite sa propre cause pour une raison de plus : il ne dit rien du site, il dit
 * quelque chose de NOUS. Un quota atteint signifie souvent que notre propre cadence est
 * en cause, et le confondre avec une erreur client enverrait le client chercher une panne
 * chez lui. Ce cas s'est présenté pendant un audit : des dizaines de feuilles de style
 * déclarées cassées étaient des 429 provoqués par ma machine non autorisée.
 *
 * ------------------------------------------------------------------------------
 * LA PLAGE ATTENDUE EST UN RÉGLAGE, ET « 200-299 » N'EST QU'UN DÉFAUT
 * ------------------------------------------------------------------------------
 *
 * Une sonde peut légitimement attendre un 301, une page de connexion en 401, ou un 404
 * sur une adresse qui doit rester introuvable. Le verdict ne porte donc pas sur « le code
 * est-il bon dans l'absolu » mais sur « est-il celui qu'on a demandé ».
 *
 * ------------------------------------------------------------------------------
 * UNE REDIRECTION INATTENDUE DIT VERS OÙ
 * ------------------------------------------------------------------------------
 *
 * C'est la signature d'un domaine détourné, d'une redirection de maintenance oubliée ou
 * d'un certificat qui renvoie vers le parking de l'hébergeur. Sans la destination,
 * l'alerte annonce un problème sans donner le seul élément qui permet de le reconnaître.
 */
final class CodeHttp implements Regle
{
    /** Ce qu'on attend quand la sonde ne précise rien : une réponse réussie. */
    public const PLAGE_PAR_DEFAUT = '200-299';

    /** Longueur d'aperçu de l'URL de destination : au-delà, elle noie la phrase. */
    private const APERCU_URL = 80;

    public function evaluer(Contexte $c): ?Verdict
    {
        $attendu = (string) ($c->reglage('expect_status') ?: self::PLAGE_PAR_DEFAUT);
        $code = $c->reponse->status;

        if (Runner::statusMatches($code, $attendu)) {
            return null;
        }

        $cause = match (true) {
            $code >= 500 => 'HTTP_5XX',
            $code === 429 => 'HTTP_429',
            $code === 404 => 'HTTP_404',
            $code === 403 => 'HTTP_403',
            $code === 401 => 'HTTP_401',
            $code >= 400 => 'HTTP_4XX',
            $code >= 300 => 'HTTP_3XX',
            default => 'HTTP_UNEXPECTED',
        };

        [$message, $variables] = match ($cause) {
            'HTTP_5XX' => ['Erreur serveur {code} : le site ne répond plus correctement', ['code' => $code]],
            'HTTP_404' => ['Page introuvable (404)', []],
            'HTTP_403' => ['Accès interdit (403)', []],
            'HTTP_401' => ['Authentification requise (401)', []],
            'HTTP_429' => ['Trop de requêtes (429) : quota serveur atteint', []],
            'HTTP_4XX' => ['Erreur client {code}', ['code' => $code]],
            'HTTP_3XX' => ['Redirection inattendue ({code}) vers {target}',
                           ['code' => $code, 'target' => str_cut($c->reponse->finalUrl, self::APERCU_URL)]],
            default => ['Code HTTP inattendu : {code}, attendu {expected}',
                        ['code' => $code, 'expected' => $attendu]],
        };

        return Verdict::pour('down', $cause, $message, $variables);
    }
}
