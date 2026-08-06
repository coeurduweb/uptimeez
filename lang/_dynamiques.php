<?php
/**
 * UptimeEZ : msgid que le code ne révèle pas par simple lecture.
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

    // --- Sondes applicatives : le libellé entre dans le verdict ----------
    // Database::probe() les passe à t() par variable, l'audit ne peut pas les
    // voir en lisant le code.
    'REST WordPress',
    'flux RSS',
    'accueil',
    'formulaire de connexion',

    // --- Certificat : verdicts écrits par le collecteur ------------------
    // Ils sont stockés en phrase source et traduits à l'affichage, comme leurs
    // voisins de Ssl::humanError().
    'Connexion TLS impossible',
    'Certificat pas encore valide : vérifiez l\'horloge du serveur',
    'Vérification du certificat échouée',

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
    '{error} affichée sur une page qui répond normalement',

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

    // --- Mois abrégés (Ui::shortDate) -----------------------------------
    // date() ne sait les écrire qu'en anglais : ils passent par t().
    'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
    'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',

    // --- Verdicts du collecteur, avec leurs variables (Runner::evaluate) --
    // Stockés comme phrase source dans last_message, traduits à l'affichage
    // par verdict_text() : la langue du cron ne décide pas de celle des écrans.
    'Tout va bien',
    '{reason} (échéance {date})',
    'Erreur serveur {code} : le site ne répond plus correctement',
    'Erreur client {code}',
    'Redirection inattendue ({code}) vers {target}',
    'Code HTTP inattendu : {code}, attendu {expected}',
    '{reason} : « {evidence} »',
    'La chaîne de contrôle « {string} » est absente de la page : le contenu n\'est plus servi, par le serveur web ou par la base de données.',
    'Page trop volumineuse pour être vérifiée en entier ({size} lus) : la chaîne de contrôle n\'a pas pu être cherchée jusqu\'au bout.',
    'Chaîne interdite détectée : « {string} »',
    'Réponse non JSON valide : {error}',
    'Champ « {field} » absent de la réponse',
    'Champ « {field} » vaut « {value} », attendu « {expected} »',
    'Certificat SSL expiré le {date}',
    'Certificat SSL invalide : {reason}',
    'Certificat SSL expire demain',
    'Certificat SSL expire dans {n} jours',
    // Verdict de la règle CorpsVide, ajoutée le 2026-08-06 par le jeu d'essai inverse.
    'La page répond {code} mais ne contient rien : le visiteur voit une page blanche.',
    // La requalification par l'écart mesuré, du même jour : une feuille en échec qui ne
    // change rien à l'aspect de la page.
    'Ressource de style en échec, mais la page n\'a pas changé d\'aspect ({ecart} % d\'écart mesuré) : {detail}',
    'Mise en page cassée : {detail}',
    'CSS dégradé : {detail}',
    'anomalie détectée à la dernière analyse',
    '(analyse du {date})',
    'Page en noindex : {detail}',
    'Temps de réponse élevé : {seconds} s',

    // --- Sondes réseau (Regle\Port, Regle\Dns), ajoutées le 2026-08-04 ---
    // Elles vivent ici pour la même raison que les autres verdicts : la phrase source est
    // stockée en base et traduite à la LECTURE par verdict_text(), donc aucun appel à t()
    // ne l'entoure dans le code, donc l'audit de traduction ne peut pas la trouver seul.
    'Port {port} fermé sur {host} : {reason}',
    'rien n\'écoute',
    'Aucun enregistrement {type} pour {name}',
    'L\'enregistrement {type} de {name} ne contient plus « {expected} » : {found}',
    'Les fichiers CSS ont changé, sans doute un déploiement.',
    'refusé',

    // --- Étiquettes de cache (Check\Css::CACHE_HINTS) -------------------
    'cache WordPress : purge en cours, ou fichier jamais régénéré',
    'Autoptimize', 'minification à la volée', 'LiteSpeed Cache',
    'CSS Elementor par page', 'WP Fastest Cache', 'Breeze', 'WP Rocket',
    'build Next.js (déploiement incomplet ?)',
    'build Vite/Laravel Mix (manifeste désynchronisé ?)',

    // --- Signatures de panne de base et d'erreur PHP (Check\Database) ----
    'Base SQLite verrouillée',
    'Connexion MySQL perdue',
    'Connexion Redis en échec',
    'Connexion base impossible',
    'Connexion base non initialisée',
    'Disque plein sur le serveur',
    'Doctrine : erreur base de données',
    'Erreur SQL (SQLSTATE)',
    'Erreur SQL WordPress',
    'Erreur applicative (Laravel)',
    'Erreur base Drupal',
    'Erreur base Joomla',
    'Erreur base de données',
    'Erreur critique WordPress',
    'Erreur critique WordPress (souvent base ou plugin)',
    'Erreur de syntaxe PHP',
    'Erreur fatale PHP',
    'Exception PHP non interceptée',
    'Exception mysqli',
    'Index MySQL corrompu (disque plein ?)',
    'Joomla : base injoignable',
    'Laravel : erreur de requête',
    'MySQL : base inconnue',
    'MySQL : identifiants refusés',
    'MySQL : trop de connexions simultanées',
    'MySQL a coupé la connexion',
    'MySQL local injoignable',
    'Mémoire PHP épuisée',
    'PDOException',
    'PostgreSQL injoignable',
    'PrestaShop : base injoignable',
    'Serveur MySQL injoignable',
    'Serveur de base injoignable',
    'Table MySQL absente du moteur',
    'Table MySQL corrompue',
    'Table absente (SQLite)',
    'Table pleine / quota disque atteint',
    'Temps d\'exécution PHP dépassé',
    'WordPress : connexion à la base impossible',
    'Échec de connexion mysqli',

    // --- Mois en clair (Report::monthLabel) ------------------------------
    'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',

    // --- Dernier code HTTP sans variable ---------------------------------
    'Trop de requêtes (429) : quota serveur atteint',
];
