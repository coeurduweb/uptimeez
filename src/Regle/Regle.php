<?php

namespace Uptimeez\Regle;

/**
 * Une règle de détection : un contexte entre, un verdict sort, ou rien.
 *
 * Rendre null veut dire « je n'ai rien à dire sur ce cas », et c'est le cas le plus
 * fréquent : la règle du certificat se tait sur une sonde qui n'est pas en HTTPS.
 * Il n'y a donc pas de verdict « tout va bien » à fabriquer, ce qui évite vingt-quatre
 * verdicts positifs à ignorer à chaque passe.
 *
 * Le contrat tient en une méthode, délibérément. Tout ce qu'on serait tenté d'y
 * ajouter (un nom, une priorité, une catégorie) appartient au registre qui ordonne les
 * règles, pas aux règles elles-mêmes : une règle qui déclare sa propre place dans la
 * liste rend l'ordre illisible, puisqu'il faut alors ouvrir vingt-quatre fichiers pour
 * le connaître.
 */
interface Regle
{
    public function evaluer(Contexte $c): ?Verdict;
}
