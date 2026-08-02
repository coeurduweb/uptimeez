<?php

namespace Uptimeez\Regle;

use Uptimeez\Runner;

/**
 * Une sonde d'API rend-elle du JSON, et ce JSON dit-il ce qu'on attend ?
 *
 * ------------------------------------------------------------------------------
 * UNE RÈGLE, TROIS ISSUES, ET NON TROIS RÈGLES
 * ------------------------------------------------------------------------------
 *
 * L'extraction sort les verdicts d'evaluate() un par un, et celle-ci en emporte
 * trois d'un coup : JSON_INVALID, JSON_PATH et JSON_VALUE. Ce n'est pas un
 * relâchement de la méthode, c'est que les trois forment une SEULE chaîne de
 * décision : on décode, et si le décodage échoue il n'y a pas de champ à chercher ;
 * on cherche le champ, et s'il est absent il n'y a pas de valeur à comparer.
 *
 * En faire trois classes obligerait chacune à redécoder le corps et à revérifier
 * qu'on est bien sur une sonde d'API. Trois décodages par passe pour une séparation
 * qui ne sépare rien, puisqu'aucune des trois ne peut être utile sans les autres.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CETTE SONDE EXISTE, ET CE QU'ELLE ÉVITE
 * ------------------------------------------------------------------------------
 *
 * Un point d'entrée d'API répond 200 avec une page de connexion HTML le jour où la
 * session expire, ou avec un JSON parfaitement valide dont le champ attendu a
 * disparu après une mise à jour. Dans les deux cas le code de statut est bon et
 * l'intégration d'en face est cassée. C'est aussi la sonde utilisée sur
 * « /wp-json/wp/v2/pages » pour prouver qu'un WordPress sert vraiment sa base et
 * non une page mise en cache : là, un cache rend l'analyse du HTML inutile, et
 * seul un point d'entrée non caché dit la vérité.
 *
 * ------------------------------------------------------------------------------
 * LE CORPS VIDE N'EST PAS UN JSON INVALIDE
 * ------------------------------------------------------------------------------
 *
 * Une réponse sans corps est traitée ailleurs, par les règles de réseau et de code
 * de statut. La signaler ici en plus donnerait deux verdicts pour une seule panne,
 * et le plus bavard des deux masquerait le plus juste.
 */
final class ReponseJson implements Regle
{
    /** Longueur d'aperçu d'une valeur inattendue : au-delà, elle noie la phrase. */
    private const APERCU = 40;

    public function evaluer(Contexte $c): ?Verdict
    {
        if ($c->reglage('kind') !== 'api') {
            return null;
        }

        $corps = $c->reponse->body;
        $json = json_decode($corps, true);

        if ($corps !== '' && $json === null && json_last_error() !== JSON_ERROR_NONE) {
            return Verdict::pour('down', 'JSON_INVALID', 'Réponse non JSON valide : {error}',
                ['error' => json_last_error_msg()]);
        }

        $chemin = (string) $c->reglage('json_path', '');

        if ($chemin === '') {
            return null;
        }

        $valeur = Runner::jsonPath($json, $chemin);

        if ($valeur === null) {
            return Verdict::pour('down', 'JSON_PATH', 'Champ « {field} » absent de la réponse',
                ['field' => $chemin]);
        }

        $attendue = (string) $c->reglage('json_expect', '');

        if ($attendue !== '' && (string) $valeur !== $attendue) {
            return Verdict::pour('down', 'JSON_VALUE',
                'Champ « {field} » vaut « {value} », attendu « {expected} »',
                ['field' => $chemin, 'value' => str_cut((string) $valeur, self::APERCU),
                 'expected' => $attendue]);
        }

        return null;
    }
}
