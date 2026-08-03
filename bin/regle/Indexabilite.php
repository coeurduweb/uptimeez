<?php

/**
 * Le noindex oublié : la seule règle dont la panne ne se voit pas sur le site.
 *
 * CE QUI EST GARDÉ ICI. Le contrôle est OPTIONNEL, et il doit l'être : une page
 * délibérément exclue de l'index n'est pas en panne, et l'alerte serait permanente. Il
 * n'agit aussi que sur du HTML : chercher une balise robots dans un JSON ou une image
 * n'aurait aucun sens et produirait du bruit sur toutes les sondes d'API.
 *
 * ET LE DÉTAIL EST DANS LE VERDICT, pas seulement le fait : l'en-tête HTTP et la balise
 * de la page ne se corrigent ni au même endroit ni par la même personne, donc dire
 * « en noindex » sans dire où transformerait l'alerte en chasse au trésor.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\Indexabilite;

$regle = new Indexabilite();

$html = static fn (string $corps, array $entetes = []): array =>
    ['body' => $corps, 'contentType' => 'text/html; charset=utf-8', 'headers' => $entetes];

titre('Le contrôle est optionnel, et éteint par défaut');

check('réglage absent : rien à dire, même sur une page en noindex',
    verdict($regle->evaluer(contexte([], reponse($html('<meta name="robots" content="noindex">'))))), null);

check('réglage à zéro : le contrôle est éteint',
    verdict($regle->evaluer(contexte(['check_noindex' => 0],
        reponse($html('<meta name="robots" content="noindex">'))))), null);

titre('Il ne lit que du HTML');

check('un JSON en 200 : rien à dire',
    verdict($regle->evaluer(contexte(['check_noindex' => 1],
        reponse(['body' => '{"robots":"noindex"}', 'contentType' => 'application/json'])))), null);

check('une page en 404 : rien à dire, ce n\'est pas la règle qui en parle',
    verdict($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<meta name="robots" content="noindex">') + ['status' => 404])))), null);

titre('La balise de la page');

check('meta robots noindex : dégradé, cause NOINDEX',
    verdict($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<html><head><meta name="robots" content="noindex, nofollow">'))))),
    ['etat' => 'degraded', 'cause' => 'NOINDEX']);

check('le message dit que la balise est en cause, et cite sa valeur',
    message($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<html><head><meta name="robots" content="noindex, nofollow">'))))),
    'Page en noindex : balise meta robots : noindex, nofollow');

check('une balise robots sans noindex : rien à dire',
    verdict($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<meta name="robots" content="index, follow">'))))), null);

titre('L\'en-tête HTTP, qui suffit à lui seul');

check('X-Robots-Tag : noindex, sans aucune balise dans la page',
    verdict($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<html><body>rien</body></html>', ['x-robots-tag' => 'noindex']))))),
    ['etat' => 'degraded', 'cause' => 'NOINDEX']);

check('et le message nomme l\'en-tête, parce que ce n\'est pas le même fichier à corriger',
    message($regle->evaluer(contexte(['check_noindex' => 1],
        reponse($html('<html>x', ['x-robots-tag' => 'noindex']))))),
    'Page en noindex : en-tête X-Robots-Tag : noindex');

bilan('Indexabilite');
