<?php
declare(strict_types=1);

namespace Uptimeez\Check;

/**
 * Un port TCP répond-il ?
 *
 * ------------------------------------------------------------------------------
 * CE QUE CE DÉTECTEUR SAIT, ET CE QU'IL NE PRÉTEND PAS SAVOIR
 * ------------------------------------------------------------------------------
 *
 * Il ouvre une connexion et la referme. C'est tout, et c'est déjà la seule question
 * intéressante : quelque chose écoute-t-il là. Il ne parle aucun protocole, donc il ne dit
 * rien de l'état du service derrière le port : un SMTP qui accepte la connexion et refuse
 * tous les messages répondra « ouvert ». La documentation le dit, parce qu'un contrôle qui
 * laisse croire à plus que ce qu'il mesure est exactement ce que ce produit reproche aux
 * autres.
 *
 * POURQUOI PAS DE PING ICMP À CÔTÉ. Un ping répond quand le service est mort, donc il
 * fabrique de la fausse tranquillité. Un port fermé, lui, est un fait exploitable : ou bien
 * le processus n'écoute plus, ou bien un pare-feu s'est mis entre les deux.
 *
 * LA CONNEXION EST FERMÉE TOUT DE SUITE, sans rien lire. Attendre une bannière ferait
 * dépendre le verdict d'un protocole, et un service qui parle en premier n'est pas la
 * règle : HTTP attend la requête, MySQL envoie sa bannière, Redis ne dit rien.
 */
final class Port
{
    /** Au-delà, on considère que rien n'écoute : c'est aussi le défaut d'un navigateur. */
    public const DELAI_PAR_DEFAUT = 10;

    /**
     * @return array{checked:bool,open:bool,ms:int,error:?string}
     */
    public static function probe(string $hote, int $port, int $delaiSec = self::DELAI_PAR_DEFAUT): array
    {
        $hote = trim($hote);

        if ($hote === '' || $port < 1 || $port > 65535) {
            return ['checked' => false, 'open' => false, 'ms' => 0,
                    'error' => t('Hôte ou port invalide')];
        }

        $debut = microtime(true);
        $errno = 0;
        $erreur = '';

        // fsockopen et non stream_socket_client : le premier suffit, existe partout, et ne
        // demande aucun contexte. Le second n'apporterait que du TLS, dont on ne veut pas
        // ici : négocier du TLS ferait échouer un port qui écoute en clair.
        $flux = @fsockopen($hote, $port, $errno, $erreur, max(1, $delaiSec));
        $ms = (int) round((microtime(true) - $debut) * 1000);

        if ($flux === false) {
            return ['checked' => true, 'open' => false, 'ms' => $ms,
                    'error' => trim($erreur) !== '' ? trim($erreur) : t('connexion refusée')];
        }

        fclose($flux);

        return ['checked' => true, 'open' => true, 'ms' => $ms, 'error' => null];
    }
}
