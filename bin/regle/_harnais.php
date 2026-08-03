<?php

/**
 * Le harnais des tests de règle : un fichier par règle, chacun exécutable seul.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI UN FICHIER PAR RÈGLE, ET NON UNE SECTION DANS LE SELFTEST
 * ------------------------------------------------------------------------------
 *
 * Le selftest fait 1 151 contrôles et met plusieurs secondes. Quand on modifie une règle,
 * on veut relancer CETTE règle, en moins d'une seconde, et lire dix lignes plutôt que
 * mille. Un fichier par règle donne exactement ça :
 *
 *     php bin/regle/Lenteur.php
 *
 * Et l'ensemble, pour le déploiement comme pour la relecture :
 *
 *     php bin/regles.php
 *
 * ------------------------------------------------------------------------------
 * CE QUE CES TESTS ÉPROUVENT, ET CE QU'ILS N'ÉPROUVENT PAS
 * ------------------------------------------------------------------------------
 *
 * Une règle EN ISOLATION : un contexte entre, un verdict sort, ou rien. Aucun réseau,
 * aucune base, aucun détecteur réel : les résultats des `Check/` sont fournis à la main,
 * ce qui est justement l'intérêt, puisqu'on peut alors décrire des cas qu'un site réel ne
 * produit qu'une fois par an.
 *
 * Ils n'éprouvent PAS l'ordre des règles ni le plafonnement du Verdict : l'ordre appartient
 * au registre `Runner::REGLES` et le plafond au Verdict, tous deux gardés dans le selftest.
 * Une règle qui demanderait « hors service » sur une cause d'apparence serait plafonnée
 * sans qu'elle le sache, et c'est le comportement voulu : ici on vérifie ce que la règle
 * DEMANDE, là-bas ce que le produit ACCEPTE de dire.
 *
 * ------------------------------------------------------------------------------
 * LE CAS « RIEN À DIRE » EST UN CAS DE TEST À PART ENTIÈRE
 * ------------------------------------------------------------------------------
 *
 * Le silence est la réponse la plus fréquente d'une règle, et c'est aussi la plus facile à
 * casser sans le voir : une condition inversée transforme un silence en alerte sur tout le
 * parc. Chaque fichier commence donc par ses cas nuls.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

require __DIR__ . '/../../src/bootstrap.php';

// Les messages sont des phrases SOURCE, en français : on les compare telles quelles, sans
// dépendre d'un catalogue de traduction qui pourrait manquer une clé.
Uptimeez\I18n::init('fr');

use Uptimeez\Regle\Contexte;
use Uptimeez\Regle\Verdict;
use Uptimeez\Response;

/** @var int $pass */
$pass = 0;
/** @var int $fail */
$fail = 0;

function check(string $label, mixed $got, mixed $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 58 - mb_strlen($label)));
    echo ($ok ? ' OK  ' : 'FAIL ') . $label . $pad
       . ($ok ? '' : '→ obtenu ' . var_export($got, true) . ', attendu ' . var_export($want, true)) . "\n";
}

/**
 * Une réponse HTTP fabriquée : seuls les champs qui intéressent la règle sont donnés.
 *
 * `ok` vaut vrai par défaut et `status` 200, parce que c'est le cas dont toutes les règles
 * sauf une partent. La règle de la couche réseau, elle, passe explicitement l'inverse.
 *
 * @param array<string,mixed> $champs
 */
function reponse(array $champs = []): Response
{
    $r = new Response();
    $r->ok = true;
    $r->status = 200;
    $r->body = '';
    $r->url = 'https://exemple.fr/';
    $r->finalUrl = 'https://exemple.fr/';
    $r->totalMs = 120;

    foreach ($champs as $cle => $valeur) {
        $r->{$cle} = $valeur;
    }

    return $r;
}

/**
 * Un contexte de sonde, avec ses réglages et ses détecteurs.
 *
 * @param array<string,mixed> $sonde
 * @param array<string,mixed> $detecteurs
 */
function contexte(array $sonde = [], ?Response $reponse = null, array $detecteurs = []): Contexte
{
    return new Contexte(
        $sonde + ['id' => 1, 'url' => 'https://exemple.fr/'],
        $reponse ?? reponse(),
        $detecteurs
    );
}

/** L'état et la cause d'un verdict, ou null : la forme comparée par tous ces tests. */
function verdict(?Verdict $v): ?array
{
    return $v === null ? null : ['etat' => $v->etat, 'cause' => $v->cause];
}

/** Le message rendu, variables substituées, tel qu'un exploitant le lira. */
function message(?Verdict $v): ?string
{
    if ($v === null) {
        return null;
    }

    return t($v->message, $v->variables);
}

function titre(string $s): void
{
    echo "\n=== $s ===\n";
}

/**
 * Le bilan, et le code de sortie qui va avec.
 *
 * Un test qui échoue doit faire échouer le déploiement : bin/regles.php s'appuie sur ce
 * code.
 */
function bilan(string $regle): never
{
    global $pass, $fail;
    echo "\n" . str_repeat('─', 68) . "\n";
    echo $fail === 0
        ? "✅ $regle : $pass contrôle(s), aucun échec.\n"
        : "⚠️  $regle : $fail échec(s) sur " . ($pass + $fail) . " contrôle(s).\n";
    exit($fail === 0 ? 0 : 1);
}
