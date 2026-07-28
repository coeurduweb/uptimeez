<?php
/**
 * Uptimer : msgid que le code ne révèle pas par simple lecture.
 *
 * Deux familles :
 *   1. les libellés passés par variable (états d'une sonde) ;
 *   2. les verdicts complets écrits en base par le collecteur, retraduits à
 *      l'affichage. Un verdict qui contient une valeur mesurée (« HTTP 503 sur
 *      /panier ») n'est pas listé : il n'y a rien à traduire d'utile dedans.
 *
 * Ce fichier n'est jamais exécuté par l'application : il documente pour
 * bin/i18n-audit.php les chaînes à couvrir dans chaque catalogue.
 */
declare(strict_types=1);

return [
    // --- États d'une sonde (Ui::LABELS) ---------------------------------
    'Opérationnel',
    'À surveiller',
    'Hors service',
    'En pause',
    'Pas encore vérifié',
    'Inconnu',

    // --- Verdicts réseau et HTTP ----------------------------------------
    'Délai dépassé (timeout)',
    'Nom de domaine non résolu',
    'Erreur réseau',
    'Connexion interrompue par le serveur',
    'Accès interdit (403)',
    'Échec de la négociation TLS',

    // --- Certificat ------------------------------------------------------
    'Certificat SSL expiré',
    'Certificat expiré',
    'Certificat auto-signé',
    'Chaîne de certification incomplète',
    'Autorité de certification inconnue du système',
    'Le certificat ne couvre pas ce domaine',
    'Certificat refusé : autorité de certification non reconnue ou chaîne incomplète',

    // --- Base de données -------------------------------------------------
    'Erreur base de données',
    'MySQL : trop de connexions simultanées',
    'MySQL : identifiants refusés',
    'MySQL a coupé la connexion',
    'Mémoire PHP épuisée',
    'Exception PHP non interceptée',
    'Disque plein sur le serveur',
    'Base SQLite verrouillée',
    'Connexion Redis en échec',
    'Doctrine : erreur base de données',
    'Laravel : erreur de requête',
    'Connexion base non initialisée',

    // --- Mise en page ----------------------------------------------------
    'Aucune feuille de style détectée sur cette page.',
    'Plus aucune media query : la mise en page responsive est perdue.',
    'Les fichiers CSS ont changé (déploiement ?)',
    'Le contenu de la page a changé',
];
