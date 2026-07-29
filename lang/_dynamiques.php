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

    // --- Causes de lenteur (Check\Vitals::analyse) ----------------------
    // Ces phrases sont composées dans un tableau puis traduites à l'affichage.
    'Le serveur met {ms} avant de renvoyer le premier octet.',
    'Aucun affichage ne peut commencer avant. Le LCP ne sera jamais meilleur que ce temps.',
    'Cache de pages côté serveur, ou un hébergement moins chargé. Le seuil visé est 800 ms.',
    '{n} script(s) de l\'en-tête bloquent l\'analyse du HTML.',
    'Le navigateur arrête de lire la page pour les télécharger et les exécuter, avant même d\'avoir affiché quoi que ce soit.',
    'Ajouter defer, ou async pour ce qui est indépendant. Un seul attribut par balise.',
    '{n} feuille(s) de style bloquent le premier affichage, {kb} au total.',
    'Rien ne s\'affiche avant que tout ce CSS soit téléchargé et analysé.',
    'Regrouper les fichiers, retirer ce qui ne sert pas à la première vue, et charger le reste en media="print" onload.',
    'La grande image du haut de page est en chargement différé.',
    'loading="lazy" dit au navigateur de la charger en dernier. C\'est pourtant elle que le visiteur attend, et c\'est elle que le LCP mesure.',
    'Retirer loading="lazy" sur cette image, et ajouter fetchpriority="high".',
    'L\'image du haut de page pèse {kb}.',
    'Sur un téléphone en 4G, ce seul fichier ajoute plus d\'une seconde avant que la page paraisse complète.',
    'Réencoder en WebP ou AVIF, servir la taille réellement affichée, viser moins de 150 Ko.',
    '{n} image(s) sans largeur ni hauteur déclarées.',
    'Le navigateur ne peut pas réserver la place : le texte saute quand l\'image arrive. C\'est la première cause de décalage de mise en page.',
    'Ajouter width et height sur la balise, ou aspect-ratio en CSS. Les valeurs servent de proportion, pas de taille finale.',
    '{n} police(s) chargées sans font-display.',
    'Le texte reste invisible pendant le téléchargement de la police, puis apparaît d\'un coup en décalant la mise en page.',
    'Ajouter font-display: swap dans la règle @font-face.',
    '{n} domaines tiers chargent du script dans l\'en-tête.',
    'Chacun ajoute une résolution DNS, une négociation TLS et du travail sur le fil principal, ce qui retarde la réaction au premier clic.',
    'Charger les traceurs après l\'affichage, ou les regrouper. Un gestionnaire de balises compte pour un domaine, pas pour zéro.',
];
