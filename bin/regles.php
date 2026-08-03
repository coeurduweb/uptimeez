<?php

/**
 * Les tests de règle, tous, en une commande.
 *
 * POURQUOI CE FICHIER EXISTE À CÔTÉ DES AUTRES. Un test par règle sert à travailler sur UNE
 * règle : on relance dix lignes plutôt que mille et on lit la sortie en un coup d'œil. Mais
 * un déploiement, lui, doit tout passer, et personne ne se souviendra d'ajouter le onzième
 * fichier à une liste écrite à la main. Le dossier est donc PARCOURU, pas énuméré.
 *
 * LE CONTRÔLE QUI COMPTE VRAIMENT EST LE DERNIER : chaque règle du registre Runner::REGLES
 * doit avoir son fichier de test. Sans lui, ajouter une règle sans la tester passerait
 * inaperçu, ce qui est exactement le trou que ces fichiers existent pour fermer.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

require __DIR__ . '/../src/bootstrap.php';

use Uptimeez\Runner;

$dossier = __DIR__ . '/regle';
$fichiers = glob($dossier . '/*.php') ?: [];
$fichiers = array_values(array_filter($fichiers,
    static fn (string $f): bool => !str_starts_with(basename($f), '_')));
sort($fichiers);

$binaire = PHP_BINARY;
$echecs = [];
$total = 0;

foreach ($fichiers as $fichier) {
    $nom = basename($fichier, '.php');
    $sortie = [];
    $code = 0;
    exec(escapeshellarg($binaire) . ' ' . escapeshellarg($fichier) . ' 2>&1', $sortie, $code);

    $texte = implode("\n", $sortie);
    preg_match('~(\d+) contrôle\(s\)~u', $texte, $m);
    $n = (int) ($m[1] ?? 0);
    $total += $n;

    if ($code !== 0) {
        $echecs[] = $nom;
        printf("FAIL %-22s\n%s\n", $nom, $texte);
        continue;
    }

    printf(" OK  %-22s %3d contrôle(s)\n", $nom, $n);
}

// LA RÈGLE SANS TEST : le seul contrôle de ce fichier, et le seul qu'aucun des autres ne
// peut faire, puisqu'un fichier absent n'exécute rien.
$testees = array_map(static fn (string $f): string => basename($f, '.php'), $fichiers);
$attendues = array_map(
    static fn (string $classe): string => substr($classe, strrpos($classe, '\\') + 1),
    Runner::REGLES
);
// TROIS RÈGLES HORS REGISTRE, ET CHACUNE POUR UNE RAISON DIFFÉRENTE.
//
// La couche réseau répond « y a-t-il une réponse à examiner », pas « qu'est-ce qui est
// cassé » : elle précède la boucle et l'arrête.
//
// Le port et le DNS ne parlent pas HTTP : le collecteur les appelle directement, sans faire
// traverser les dix autres règles à une sonde qui n'attend pas de page. Elles ont donc leur
// test comme les autres, et ce fichier l'exige : une règle hors registre est justement celle
// qu'on oublierait de tester, puisque rien ne la liste.
$attendues[] = 'CoucheReseau';
$attendues[] = 'Port';
$attendues[] = 'Dns';

$sansTest = array_values(array_diff($attendues, $testees));
$sansRegle = array_values(array_diff($testees, $attendues));

echo "\n" . str_repeat('─', 68) . "\n";

if ($sansTest !== []) {
    echo "⚠️  Règle(s) sans fichier de test : " . implode(', ', $sansTest) . "\n";
}

if ($sansRegle !== []) {
    echo "⚠️  Fichier(s) de test sans règle correspondante : " . implode(', ', $sansRegle) . "\n";
}

if ($echecs === [] && $sansTest === [] && $sansRegle === []) {
    printf("✅ %d règle(s), %d contrôle(s), aucun échec.\n", count($fichiers), $total);
    exit(0);
}

printf("⚠️  %d règle(s) en échec.\n", count($echecs) + count($sansTest) + count($sansRegle));
exit(1);
