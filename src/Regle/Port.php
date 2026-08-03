<?php

namespace Uptimeez\Regle;

/**
 * Le port répond-il ? La règle la plus courte du moteur, et la seule sans nuance.
 *
 * ------------------------------------------------------------------------------
 * PAS DE « DÉGRADÉ » ICI, ET C'EST LE POINT
 * ------------------------------------------------------------------------------
 *
 * Un port est ouvert ou fermé. Il n'y a pas d'état intermédiaire à inventer : un port lent à
 * accepter la connexion est un port ouvert, et le mesurer sert à afficher une durée, pas à
 * fabriquer un verdict. Les autres règles ont des nuances parce qu'une page peut arriver et
 * être fausse ; un port ne peut pas être « à moitié ouvert ».
 *
 * CE QU'IL NE FAUT PAS EN CONCLURE, ET LA FICHE LE DIT AUSSI : un port ouvert ne prouve pas
 * que le service derrière fonctionne. Un SMTP qui accepte la connexion et refuse tous les
 * messages répond « ouvert ». C'est la limite du contrôle, pas un défaut à corriger : y
 * ajouter un dialogue par protocole ferait de ce moteur autre chose que ce qu'il est.
 */
final class Port implements Regle
{
    /** Le nom sous lequel le collecteur dépose le résultat de la sonde. */
    public const DETECTEUR = 'port';

    public function evaluer(Contexte $c): ?Verdict
    {
        $sonde = $c->detecteur(self::DETECTEUR);

        // Pas de sonde, ou une sonde qui n'a pas abouti : se taire est le seul verdict
        // honnête. Dire « fermé » sur une sonde qu'on n'a pas su faire accuserait le port
        // d'un défaut qui est le nôtre.
        if (! is_array($sonde) || ($sonde['checked'] ?? false) !== true) {
            return null;
        }

        if (($sonde['open'] ?? false) === true) {
            return null;
        }

        return Verdict::pour('down', 'PORT_CLOSED',
            'Port {port} fermé sur {host} : {reason}',
            [
                'port' => (string) ($sonde['port'] ?? '?'),
                'host' => (string) ($sonde['host'] ?? '?'),
                'reason' => ((string) ($sonde['error'] ?? '')) ?: t('rien n\'écoute'),
            ]);
    }
}
