<?php
/**
 * UptimeEZ, catalogue français (langue source des clés).
 *
 * Ce catalogue est volontairement VIDE. Un fichier vide ressemblant toujours à
 * un oubli, voici pourquoi c'en est la seule forme juste.
 *
 * 1. Les msgid sont déjà français.
 *    La clé de traduction est la phrase française du code source, à la manière
 *    de gettext (voir l'en-tête de src/I18n.php). Un catalogue français serait
 *    donc une table identité : plus de mille cinq cents lignes où la valeur
 *    recopie la clé, mot pour mot.
 *
 * 2. Le repli EST la traduction française.
 *    I18n::t() rend le msgid quand le catalogue ne dit rien :
 *        $out = self::$cat[$msgid] ?? null;
 *        if ($out === null || $out === '') $out = $msgid;   // phrase française
 *    Recopier l'identité n'ajouterait aucune information, et créerait une
 *    seconde source à faire dériver : le jour où une phrase change dans le
 *    code, la copie mentirait sans que rien ne le signale.
 *
 * 3. Ce fichier n'est même pas chargé à l'affichage.
 *    I18n::init() traite la langue source à part : « if (self::$lang ===
 *    self::SOURCE) self::$cat = []; ». Le français s'affichait donc déjà
 *    correctement. Ce qui manquait, c'était le FICHIER : lang/ ne contenait
 *    que neuf catalogues pour dix langues déclarées dans LANGS, si bien que
 *    tout inventaire qui compte les fichiers (bin/selftest.php, un mainteneur
 *    qui fait un ls) concluait à l'absence du français.
 *
 * À SAVOIR AVANT D'ÉCRIRE ICI : ajouter une entrée dans ce tableau ne changera
 * rien à l'écran, puisque init() ne lit pas ce fichier pour la langue source.
 * Pour corriger une formulation française, on corrige la phrase DANS LE CODE,
 * qui en est la source unique, puis on relance bin/i18n-sync.php pour propager
 * la nouvelle clé aux neuf autres catalogues.
 *
 * Pour la même raison, « php bin/i18n-audit.php --manquants=fr » listera tous
 * les msgid du produit : c'est attendu et sans portée, l'audit compare une
 * langue à la source et la source ne se compare pas à elle-même.
 *
 * Conventions rappelées, valables pour les dix catalogues :
 *   - le nombre fait partie du msgid (« {n} s », « il y a {duree} ») : un
 *     msgid réduit à une lettre ou à une unité serait intraduisible, faute de
 *     contexte, et le pluriel deviendrait impossible à rendre ;
 *   - « {app} » est un emplacement, substitué par I18n::t(). Il se recopie tel
 *     quel : aucun catalogue n'écrit le nom du produit en clair, ce qui a rendu
 *     deux renommages indolores ;
 *   - une variable présente dans la clé doit survivre dans la traduction,
 *     bin/selftest.php le vérifie ;
 *   - les formes plurielles au-delà de deux (russe, arabe) se séparent par
 *     « | » dans la traduction du pluriel.
 */
declare(strict_types=1);

// Catalogue identité : voir l'en-tête. Le garde-fou de bin/selftest.php vérifie
// que ce tableau reste vide, pour que personne ne le remplisse en croyant
// traduire quelque chose.
return [];
