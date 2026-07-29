<div align="center">

# Uptimer

### La surveillance de sites qui vous dit **quoi faire**, pas seulement ce qui est cassé.

**Monitoring auto-hébergé pour ceux qui gèrent les sites des autres.**
Il repère les mises en page cassées, les bases de données mortes derrière un HTTP 200, les certificats qui
expirent et les tâches cron devenues silencieuses, puis vous donne une liste de tâches avec le correctif à un
clic.

Aucune dépendance · Pas de Docker · Tourne sur un mutualisé · SQLite ou MySQL · 10 langues

[Pourquoi Uptimer](#pourquoi-un-outil-duptime-de-plus-) ·
[Captures](#à-quoi-ça-ressemble) ·
[Ce qu'il détecte](#ce-quil-détecte-vraiment) ·
[Comparatif](#face-aux-concurrents) ·
[Installation en 60 secondes](#installation-en-60-secondes) ·
[L'interroger depuis Claude](#le-piloter-depuis-un-agent-mcp) ·
[Documentation](docs/fr/README.md) ·
[English version](README.md)

<img src="docs/img/tour.gif" alt="Uptimer en action : la liste de tâches du jour, les aides contextuelles, la palette de commandes, les correctifs en un clic et le mur d'écran" width="820">

</div>

---

## Pourquoi un outil d'uptime de plus ?

Tous les outils du marché répondent à la même question : **est-ce que le site répond ?**

Cette question a cessé d'être intéressante depuis longtemps. Un site peut renvoyer `200 OK` en 180 ms et être
complètement cassé : la feuille de style est en 404 après un déploiement et le visiteur voit du HTML nu ; la
base de données est tombée et WordPress affiche une jolie page d'erreur avec un code impeccable ; quelqu'un a
laissé un `noindex` après une mise en ligne et Google déréférence tranquillement.

Uptimer est né de trois constats faits en gérant un parc d'agence avec les alternatives :

| Ce qui coince ailleurs | Ce que fait Uptimer |
|---|---|
| **La configuration est un impôt.** Vingt écrans, quarante champs avant d'avoir surveillé quoi que ce soit. | Vous collez une liste de domaines. Il détecte la technologie, choisit les pages qui valent la peine, déduit la chaîne qui prouve que la base répond, cale les seuils sur le p95 mesuré, puis montre un **aperçu avant de créer quoi que ce soit**. |
| **Les alertes deviennent du bruit.** Un serveur tombe, quarante e-mails arrivent. Au bout d'une semaine, personne ne les lit. | Les pannes qui partagent une IP deviennent **une seule alerte groupée**. Les seuils s'ajustent seuls : un site lent par nature ne crie pas au loup. Heures calmes, fenêtres de maintenance, prise en compte, relances avant alerte. |
| **Les tableaux de bord montrent des états, pas des actions.** Des points verts et rouges ; à vous de deviner quoi faire. | L'écran d'accueil est une **liste de tâches** : la cause, pourquoi c'est un problème, quoi faire, la preuve, et les boutons qui le font sans quitter la page. Chaque action est annulable. |

> Les autres montrent **des états**. Uptimer donne **une liste de choses à faire**, et devine tout le reste.

---

## Tout ce qu'il surveille

La plupart des outils surveillent une chose : est-ce que le serveur répond. Uptimer surveille **cinq couches**,
sur chaque page qu'il vérifie, sans vous demander d'en configurer une seule.

| | Ce qu'il surveille | Ce que ça attrape |
|---|---|---|
| **Est-ce que ça répond ?** | Code HTTP comparé à une plage attendue, temps de DNS, de connexion, de TLS et de premier octet, chaîne de redirections, relances avant d'alerter | Une panne, un délai dépassé, une négociation de certificat qui échoue, une boucle de redirection, un site passé en `www` |
| **Est-ce que la page est juste ?** | Chaque feuille de style, script et police : disponibilité, type MIME, `nosniff`, contenu mixte, CSP, SRI, poids comparé à une référence apprise, couverture des classes, media queries, blocs en attente d'animation | Un déploiement qui met le CSS en 404, la moitié de la feuille disparue, la mise en page responsive perdue, une page *invisible* |
| **Est-ce que les données répondent ?** | Environ 45 signatures de panne de base, une sonde CMS qui traverse réellement la base, et une chaîne de preuve déduite du contenu du site | WordPress qui sert une page d'erreur avec un `200` impeccable, une table tronquée, un disque plein |
| **Est-ce rapide pour le visiteur ?** | Temps de réponse du serveur en millisecondes, fichiers qui bloquent l'affichage avec leur poids exact, image du haut de page et son poids, images sans dimensions, polices sans `font-display`, scripts tiers. Et les vrais LCP, INP et CLS avec une clé gratuite du Chrome UX Report | Une image de bandeau en chargement différé, 400 Ko de CSS bloquant, une page qui saute pendant le chargement |
| **Est-ce que ça va casser bientôt ?** | Expiration du certificat (inspection TLS en deux passes), expiration du domaine par RDAP, failles publiées sur les versions lues dans le HTML, et un battement dead-man pour les tâches qui doivent tourner | Un certificat expiré un samedi, un domaine que personne n'a renouvelé, une extension avec un avis de trois jours, une sauvegarde arrêtée en silence |

**Cinq types de sondes**, chacune avec ses réglages : une **page**, une **API JSON** (chemin du champ, valeur
attendue, en-têtes, corps, n'importe quelle méthode), un **fichier** (une ressource qui doit rester joignable et
inchangée), un **mot-clé** (un texte qui doit apparaître, ou ne jamais apparaître) et un **battement** (votre
script appelle Uptimer quand il a fini ; c'est le silence qui alerte).

Et ce qu'il fait de tout ça : les pannes qui partagent une IP deviennent **une seule** alerte, les seuils se
règlent sur le p95 mesuré, chaque décision est consignée dans un journal lisible, et l'écran d'accueil transforme
l'ensemble en liste de tâches.

→ **[Tout ce qu'il surveille, en détail](docs/fr/etendue.md)**

---

## À quoi ça ressemble

<table>
<tr>
<td width="50%" valign="top">

**La journée commence ici.** Une carte par problème, les plus urgents d'abord : la cause, la conséquence, le
remède, et les boutons qui l'appliquent sur place.

<img src="docs/img/today.png" alt="Écran d'accueil d'Uptimer : liste des sites à traiter, chacun avec sa cause, son explication, son remède et les boutons d'action">

</td>
<td width="50%" valign="top">

**Le mur d'écran**, pour l'écran du bureau. Vert, orange, rouge. Les sites en souffrance remontent en haut,
jamais sous la ligne de flottaison.

<img src="docs/img/wall.png" alt="Mur d'écran d'Uptimer : cartes colorées par site avec uptime, temps de réponse et courbe des 24 dernières heures">

</td>
</tr>
<tr>
<td width="50%" valign="top">

**La mise en page cassée, montrée.** Pas un chiffre, une image : la page telle qu'un visiteur la voit, à côté de
ce qu'elle était, avec l'écart mesuré. Reconstruite depuis le HTML et le CSS chargé, sans navigateur.

<img src="docs/img/silhouette.png" alt="Silhouettes côte à côte : la page de référence avec son conteneur centré et ses trois colonnes, et la page actuelle avec tout empilé sur toute la largeur, marquée 71 % de différence">

</td>
<td width="50%" valign="top">

**Et la cause exacte juste en dessous.** Pas « le CSS a l'air bizarre » : le fichier fautif, son code HTTP, et le
message que la console du navigateur aurait affiché.

<img src="docs/img/css-broken.png" alt="Panneau des ressources de la page montrant la feuille de style en échec, son code HTTP, la cause et les erreurs de console reconstituées">

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Rien de caché, rien d'imposé.** Un interrupteur fait passer toute l'interface entre *Simple*, qui ne montre
que ce sur quoi on peut agir, et *Complet*, qui ouvre tous les réglages et toutes les mesures.

<img src="docs/img/detail-simple.png" alt="Fiche de sonde en mode simple, ne montrant que l'information actionnable">

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Ctrl / ⌘ + K.** N'importe quel site, n'importe quel écran, n'importe quelle action. Insensible aux accents et
à la casse dans toutes les langues : tapez `casse`, trouvez `cassé`.

<img src="docs/img/palette.png" alt="Palette de commandes ouverte, recherche de sondes et d'écrans par leur nom">

</td>
<td width="50%" valign="top">

**Un rapport à envoyer au client.** Disponibilité, interruptions, temps de réponse, bande jour par jour. À
imprimer ou à enregistrer en PDF.

<img src="docs/img/report.png" alt="Rapport client imprimable avec les chiffres de disponibilité, la bande journalière, la courbe des temps de réponse et le tableau des incidents">

</td>
</tr>
</table>

<details>
<summary><b>Plus de captures</b> : thème sombre, mobile, autres langues, aperçu d'import, réglages</summary>
<br>

| Thème sombre | Sur téléphone |
|---|---|
| <img src="docs/img/today-dark.png" alt="Écran d'accueil d'Uptimer en thème sombre"> | <img src="docs/img/mobile-today.png" alt="Écran d'accueil d'Uptimer sur un téléphone" width="300"> |

| Anglais (par défaut) | Arabe (droite à gauche) |
|---|---|
| <img src="docs/img/today-en.png" alt="Interface d'Uptimer en anglais"> | <img src="docs/img/today-ar.png" alt="Interface d'Uptimer en arabe, disposée de droite à gauche"> |

**Import : un aperçu avant que rien n'existe.** Collez des domaines, un tableau, un e-mail de client. Uptimer
y récupère les adresses et montre exactement ce qu'il va faire.

<img src="docs/img/import-preview.png" alt="Tableau d'aperçu d'import listant chaque site, sa cadence, ses pages suivies et sa chaîne de preuve déduite avant création">

**Les réglages, repliés.** Tout a une valeur par défaut sensée ; les accordéons restent fermés jusqu'à ce que
vous en ayez besoin.

<img src="docs/img/settings.png" alt="Écran de réglages d'Uptimer avec les accordéons repliés pour le cron, les alertes, les valeurs par défaut et les accès">

</details>

---

> **À propos des captures.** Elles viennent du jeu de démonstration livré avec l'outil (`php bin/demo.php`).
> Les noms de sites sont réels et reconnaissables à dessein, parce qu'une capture doit vouloir dire quelque
> chose au premier regard. **Toutes les mesures sont fictives**, l'interface le dit en permanence en mode démonstration,
> et les quatre pannes sont volontairement placées sur des sous-domaines de préproduction qui n'existent pas
> (`staging.`, `preprod.`, `beta.`, `recette.`). Rien ici n'affirme quoi que ce soit sur la fiabilité d'un
> service réel.

---

## Ce qu'il détecte vraiment

La plupart des outils vérifient un code HTTP et un mot-clé. Voici ce que surveille Uptimer, et pourquoi chaque
point compte.

### La mise en page cassée, celle que personne d'autre ne détecte sans écrire de code

Un déploiement se passe mal, la feuille de style minifiée part en 404, et le site du client ressemble à un
document texte de 1994. Code HTTP : `200`. Temps de réponse : excellent. Tous les outils d'uptime du marché
annoncent que le site va bien.

Uptimer croise **neuf signaux indépendants** sur chaque page HTML vérifiée :

| Signal | Ce qu'il attrape |
|---|---|
| Disponibilité de chaque feuille de style, script et police | Le 404 classique d'après déploiement |
| Type MIME + `nosniff` | Le serveur renvoie du HTML ou une trace PHP au lieu du CSS |
| Contenu mixte | Une ressource HTTP sur une page HTTPS, bloquée silencieusement par le navigateur |
| CSP `style-src` | Un changement de politique qui bloque votre propre feuille de style |
| Intégrité `integrity` (SRI) | Une empreinte périmée : le navigateur refuse un fichier parfaitement valide |
| Volume comparé à la référence apprise | La moitié du CSS disparue sans le moindre 404 |
| Couverture des classes | Des classes du HTML sans aucune règle CSS (tolérant aux échappements Tailwind) |
| Media queries | La mise en page responsive a disparu |
| Contenu en attente d'animation | Des blocs masqués par un script de révélation qui n'a pas chargé, une page *invisible* |

Puis il fait ce qu'aucun autre outil ne fait : il **reconstitue les messages que la console du navigateur aurait
affichés** : `net::ERR_ABORTED`, `Refused to apply style from …`, `Mixed Content: …`, `Failed to find a valid
digest …`. Le ticket transmis au développeur contient déjà la preuve.

La référence est *apprise* sur les états sains : une refonte volontaire ne réveille personne à 3 h du matin,
et quand le design change exprès, un bouton réapprend la référence.

### La base de données tombée derrière un 200 impeccable

WordPress, Laravel, Doctrine, PDO et Symfony ont chacun leur façon d'annoncer une panne de base, et tous
renvoient volontiers `200 OK`. Uptimer embarque **≈45 signatures d'erreur**, croise avec une sonde CMS qui
traverse réellement la base (l'API REST de WordPress, pas la page d'accueil en cache), et surveille la **chaîne
de preuve** : un texte qui ne peut venir que de la base, comme le copyright du pied de page.

Cette chaîne est **déduite automatiquement**, dans cet ordre de préférence : copyright du pied de page,
`og:site_name`, titre de la page, première entrée du menu, titre H1. Elle n'est jamais prise sur une page
d'erreur. Si elle disparaît alors que la page
répond encore 200, la couche données est tombée et vous le savez en une vérification.

### Quitter un autre outil prend cinq minutes

Le frein au changement n'est pas le prix, c'est la soirée à ressaisir quarante sondes. Uptimer lit donc l'export de
l'outil que vous quittez : **UptimeRobot, Uptime Kuma, Better Stack, Pingdom, Site24x7**, et un CSV générique pour
tout le reste.

![Aperçu d'une reprise](docs/img/import-reprise.png)

Déposez le fichier. Le format est reconnu **au contenu**, pas au nom. Cadences, noms, mots-clés, codes HTTP
acceptés, relances et sondes en pause sont repris tels quels, et l'aperçu distingue ce qui vient de l'export de ce
qui vient de vos valeurs par défaut.

Deux choses qu'il refuse d'arranger. **Les sondes sans équivalent** (port TCP, ping ICMP, DNS, SMTP) sont listées
avec la raison et ne sont pas créées, parce qu'un import qui perd six sondes sur quarante en silence est pire qu'un
import qui refuse. Et **le sens du mot-clé est respecté outil par outil** : le « exists » d'UptimeRobot, le
`invertKeyword` de Kuma, le `keyword_absence` de Better Stack et le `shouldnotcontain` de Pingdom veulent tous dire
« alerter quand le texte *est* là », ce qui devient une chaîne interdite ici, pas une chaîne de contrôle. Se
tromper inverserait chaque alerte.

**L'historique de mesures n'est jamais importé.** Il a été pris par un autre outil, avec d'autres seuils, depuis un
autre réseau. Un « 99,98 % » repris de Pingdom ne dirait rien de ce qu'Uptimer a mesuré : le compteur repart de
zéro, et l'écran le dit avant que vous validiez.

→ **[Reprise](docs/fr/reprise.md)**

### Pourquoi une page est lente, et quoi changer

Les Core Web Vitals viennent de vrais navigateurs Chrome. Un outil en PHP ne peut pas les calculer, et Uptimer ne
fera pas semblant : aucune mesure de navigateur n'est inventée ici. En revanche il fait ce que personne ne fait
sans lancer Chrome, parce qu'il a déjà les données sous la main.

![Vitesse ressentie](docs/img/vitals.png)

**Mesuré, pas estimé.** Le temps de réponse du serveur en millisecondes à chaque vérification, qui est le plancher
de tout le reste : le LCP ne sera jamais meilleur. Le poids exact et le temps de transfert de chaque feuille de
style et de chaque script, puisque l'audit des ressources les télécharge déjà. Le poids réel de l'image du haut de
page, obtenu par une seule requête HEAD.

**Lu dans la page, et annoncé comme tel.** Les feuilles et les scripts qui bloquent réellement le premier
affichage, `media="print"` correctement écarté. L'image du haut de page en `loading="lazy"`, qui est l'erreur de
LCP la plus répandue. Les images sans largeur ni hauteur, première cause de décalage de mise en page. Les polices
sans `font-display`. Les domaines tiers qui chargent du script dans l'en-tête.

Chaque cause porte son remède, classée par impact, la gravité lisible sur le bord de chaque ligne. Un constat sans
conduite à tenir n'est qu'un reproche.

**Les mesures de terrain si vous les voulez.** Ajoutez une clé gratuite du Chrome UX Report et les trois mesures
officielles s'affichent à côté des causes, le pire des trois décidant du verdict, exactement comme le fait Google.
Pas de clé, pas de chiffre inventé, et une page sans trafic suffisant se le voit dire plutôt que de recevoir un
vide.

→ **[Vitesse ressentie](docs/fr/vitesse.md)**

### Un lien par client, et rien de ce qui appartient aux autres

Vous surveillez trente sites qui appartiennent à douze personnes. Chacune veut savoir si le sien va bien. Aucune
n'a à voir les vingt-neuf autres.

Tous les autres outils répondent à ça par des comptes utilisateurs, des rôles et des permissions. Uptimer vous
donne un client, une liste de cases à cocher, et un lien.

![Écran des clients](docs/img/clients.png)

Le lien ouvre une page sans compte ni mot de passe : une bande qui dit **tout fonctionne** ou **un de vos sites ne
répond pas**, un bloc par site avec sa courbe des 24 heures et sa disponibilité sur 30 jours, et les interruptions
récentes avec leur durée. Aucun bouton, aucun réglage, aucun jargon, et c'est lisible sur le téléphone où le
client ouvrira vraiment le lien.

![Espace client](docs/img/client-space.png)

Ce lien vaut mot de passe, il est donc traité comme tel : jeton aléatoire de 128 bits, page en `noindex` et
`no-referrer`, cache interdit, un clic pour changer le lien s'il a circulé trop loin, un interrupteur pour fermer
l'accès sans rien perdre de l'historique. Un lien inconnu, un lien mal formé et un lien fermé donnent **la même
réponse** : tâtonner n'apprend rien.

Le cloisonnement n'est pas une affaire d'affichage : chaque lecture filtre sur le client, et aucun identifiant
venu de l'URL n'entre dans ces requêtes. Ajouter `&client_id=7` au lien de quelqu'un ne change rien. C'est
exactement ce que vérifient les suites de tests, jetons hostiles compris.

Vos sites sont déjà groupés depuis l'import ? Un bouton transforme ces groupes en clients.

→ **[Mode agence](docs/fr/mode-agence.md)**

### Les versions vulnérables, avant que quoi que ce soit ne casse

Uptimer lit déjà le HTML de chaque page qu'il vérifie, et ce HTML dit presque toujours quelle version tourne : la
balise `generator`, le paramètre `?ver=` des fichiers statiques, les chemins d'extensions. Il construit donc
**l'inventaire logiciel de chaque site** sans rien demander de plus, puis le croise avec les bases d'avis
publiques : **OSV.dev** pour Packagist (Drupal, Laravel, Symfony, TYPO3, Magento, PrestaShop, Joomla) et
**api.wordpress.org** pour le cœur WordPress, ses extensions et ses thèmes.

![Logiciels et failles connues](docs/img/vulnerabilities.png)

Deux signaux, et ils ne se mélangent jamais :

| | |
|---|---|
| **Faille publiée** | Un avis identifié couvre *précisément* la version détectée. Identifiant, date et lien sont affichés. Rien n'est déduit. |
| **Version en retard** | La version installée est antérieure à la dernière publiée. Une dette, pas une faille, et ce n'est pas dit avec les mêmes mots. |

Un outil qui crie « vulnérable » alors qu'il ne sait que « pas à jour » se fait ignorer en trois semaines, et le
jour où il a raison, personne ne regarde. La gravité n'est donc affichée que si un avis l'a annoncée, une version
illisible ne déclenche aucune interrogation au lieu d'une supposition, et mettre un site à jour remet le verdict
à zéro immédiatement.

Une interrogation par composant et par version, gardée sept jours, plafonnée par passe d'entretien : cent sites
ne produisent pas cent requêtes par jour. Ce qui sort de chez vous se limite au nom du composant et à son numéro
de version, jamais l'adresse du site concerné, et l'ensemble se coupe en un clic.

→ **[Veille de sécurité](docs/fr/veille-securite.md)**

### Certificats, domaines, et tout le reste

| | |
|---|---|
| **Certificat TLS** | Inspection en deux passes : une lecture permissive pour les faits, une validation stricte façon navigateur pour le verdict. Expiration, chaîne, autorité, correspondance du domaine, avec préavis. |
| **Expiration du domaine** | Vérification RDAP quotidienne. Un domaine expiré coupe le site *et* les e-mails, et peut être racheté par un tiers. |
| **`noindex` oublié** | Le tueur silencieux du SEO après une mise en ligne. Personne ne s'en aperçoit pendant des semaines. |
| **Modification de contenu** | Une empreinte du texte visible : repère une publication passée en ligne, comme une page défigurée. |
| **API JSON** | Chemin du champ, valeur attendue, en-têtes personnalisés, corps de requête, n'importe quelle méthode. |
| **Tâches cron silencieuses** | Un battement dead-man : votre script de sauvegarde appelle Uptimer quand il a fini. **C'est le silence qui déclenche l'alerte**, la seule panne qu'aucune requête HTTP ne peut voir. |
| **Temps de réponse** | DNS, connexion, TLS, premier octet, total. Seuil calé sur le p95 mesuré du site, pas sur un chiffre rond. |
| **Pannes groupées** | Dix sites qui tombent sur une même IP, c'est *un* incident, pas dix alertes. |
| **Rapport client mensuel** | Disponibilité, interruptions, temps de réponse, envoyés à chaque client le jour choisi. Une fois par mois, jamais deux, retenté le lendemain si le serveur de messagerie était en panne. |

---

## Face aux concurrents

Comparatif des fonctions face aux outils que l'on évalue réellement. Il reflète le **comportement par défaut
des formules standard, en juillet 2026** : sans script, sans extension, sans module payant. Une erreur ?
Ouvrez une pull request, le tableau est dans ce fichier.

| | **Uptimer** | UptimeRobot | Checkly | Site24x7 | Uptime Kuma | Zabbix | New Relic |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Détection de mise en page cassée (CSS) | ✅ automatique | ❌ | ⚠️ à scripter | ⚠️ défiguration seulement | ❌ | ❌ | ⚠️ à scripter |
| Erreurs de console reconstituées | ✅ | ❌ | ⚠️ dans les logs du script | ❌ | ❌ | ❌ | ⚠️ dans les logs |
| Image avant / après de la page cassée | ✅ silhouette | ❌ | ⚠️ capture dans un script | ⚠️ capture | ❌ | ❌ | ⚠️ capture |
| Inventaire logiciel de chaque site, versions comprises | ✅ depuis le HTML déjà reçu | ❌ | ❌ | ⚠️ avec un agent | ❌ | ✅ avec un agent | ⚠️ avec un agent |
| Faille publiée sur la version détectée | ✅ OSV + wordpress.org | ❌ | ❌ | ⚠️ produit séparé | ❌ | ⚠️ à construire | ⚠️ produit séparé |
| Base de données tombée derrière un 200 | ✅ signatures + chaîne de preuve | ⚠️ mot-clé manuel | ⚠️ assertion manuelle | ⚠️ mot-clé manuel | ⚠️ mot-clé manuel | ⚠️ à construire | ⚠️ assertion manuelle |
| Chaîne de preuve déduite automatiquement | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Alerte sur un `noindex` oublié | ✅ | ❌ | ⚠️ script | ❌ | ❌ | ❌ | ⚠️ script |
| Import depuis l'export d'un concurrent | ✅ 5 outils, reconnus seuls | ❌ | ❌ | ⚠️ CSV d'URLs | ⚠️ sa propre sauvegarde | ❌ | ❌ |
| Dit ce qu'il n'a pas pu importer, et pourquoi | ✅ | n/a | n/a | ❌ | ❌ | n/a | n/a |
| Ajout en masse avec détection du CMS | ✅ collez n'importe quoi | ⚠️ CSV, sans détection | ❌ orienté code | ⚠️ CSV | ❌ un par un | ❌ | ❌ |
| Aperçu avant création des sondes | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Core Web Vitals avec les causes expliquées | ✅ mesuré + lu dans la page | ❌ | ⚠️ score Lighthouse seulement | ⚠️ score seulement | ❌ | ❌ | ⚠️ score seulement |
| Fichiers bloquant le rendu nommés, avec leur poids | ✅ | ❌ | ⚠️ dans un rapport | ❌ | ❌ | ❌ | ⚠️ dans un rapport |
| Image du haut de page en chargement différé repérée | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Seuil de lenteur auto-ajusté | ✅ sur le p95 | ❌ fixe | ❌ fixe | ❌ fixe | ❌ fixe | ⚠️ à construire | ⚠️ formules payantes |
| Journal des décisions de l'outil | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Accueil = liste de tâches avec correctifs | ✅ | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord |
| La page cassée montrée dans la liste de tâches | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pouls du parc sur 24 h en une seule bande | ✅ | ❌ | ❌ | ⚠️ par sonde | ❌ | ⚠️ à construire | ⚠️ par application |
| Annulation de chaque action | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pannes regroupées par IP de serveur | ✅ automatique | ❌ | ❌ | ⚠️ à configurer | ❌ | ✅ topologie | ⚠️ à configurer |
| Battement dead-man (cron, sauvegardes) | ✅ | ✅ | ⚠️ | ✅ | ✅ push | ✅ | ⚠️ |
| Rapport client imprimable | ✅ intégré | ⚠️ formules payantes | ❌ | ✅ | ❌ | ⚠️ | ✅ |
| Rapport mensuel envoyé à chaque client | ✅ | ❌ | ❌ | ⚠️ interne seulement | ❌ | ❌ | ⚠️ interne seulement |
| Accès client en lecture seule, sans compte à créer | ✅ un lien | ⚠️ page d'état seulement | ❌ | ⚠️ comptes utilisateurs | ⚠️ page d'état seulement | ❌ | ⚠️ comptes utilisateurs |
| Le client ne voit que ses sites | ✅ par construction | ❌ | ❌ | ⚠️ configuration de rôles | ❌ | ⚠️ à construire | ⚠️ configuration de rôles |
| Page d'état publique | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ⚠️ |
| Interrupteur interface simple / complète | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Langues de l'interface | **10 + RTL** | 1 | 1 | plusieurs | nombreuses (communauté) | plusieurs | plusieurs |
| Tourne sur un mutualisé | ✅ PHP seul | SaaS | SaaS | SaaS | ❌ Node/Docker | ❌ serveur | SaaS |
| Dépendances à installer | **aucune** | n/a | Node + navigateurs | n/a | Node ou Docker | serveur + base + agent | agent |
| Vos données restent chez vous | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ |
| Coût pour 40 sites | **gratuit** | formule payante | formule payante | formule payante | gratuit | gratuit | formule payante |

**Là où les autres sont réellement meilleurs.** Checkly pour les parcours utilisateurs scriptés en intégration
continue ; Zabbix pour les métriques d'infrastructure sur vos propres serveurs ; New Relic pour le traçage
applicatif dans votre code ; Site24x7 pour la largeur si vous voulez un seul fournisseur pour tout ; SiteGuru
pour l'audit SEO, qui est un autre métier. Uptimer n'essaie pas d'être l'un de ceux-là. Il fait une seule
chose : **garder en vie les sites des autres sans qu'un humain passe sa journée devant des tableaux de bord.**

---

## Installation en 60 secondes

**Prérequis :** PHP 8.1 ou plus récent avec `curl`, `pdo_sqlite` (ou `pdo_mysql`) et `json`. C'est toute la
liste. Pas de Composer, pas de Node, pas de Docker, aucune étape de compilation.

```bash
# 1. Déposez les fichiers là où votre serveur web peut les servir
git clone https://github.com/loran750/uptimer.git
cd uptimer

# 2. Ouvrez install.php dans un navigateur et choisissez un mot de passe.
#    Sur mutualisé : envoyez par FTP, puis visitez https://votredomaine.fr/uptimer/install.php

# 3. Une ligne de cron, toutes les minutes, quels que soient vos intervalles
* * * * * /usr/local/bin/php /chemin/vers/uptimer/cron.php >/dev/null 2>&1
```

Uptimer choisit lui-même les sondes dues : une seule passe par minute couvre tous les intervalles, de 30
secondes à un jour. Pas d'accès à crontab ? L'écran **Réglages** vous donne une URL à appeler depuis n'importe
quel service externe.

**Envie de visiter d'abord ? Il y a un mode démonstration.** Il construit un parc de 13 sites sur des domaines
reconnaissables, 30 jours d'historique, et les quatre pannes emblématiques : mise en page cassée, base de
données morte, ralentissement, `noindex` oublié.

```bash
php bin/demo.php                  # mot de passe : demo1234
php -S 127.0.0.1:8390 -t .        # puis ouvrez http://127.0.0.1:8390/
php bin/demo.php --purge          # la retire, sans laisser de trace
```

Le mode démonstration n'est pas une autre version : c'est l'application réelle sur des données inventées. Un
bandeau permanent le dit sur chaque écran, et le script refuse d'écraser une installation existante.

**[Documentation complète](docs/fr/README.md)** : installation, spécificités o2switch et cPanel, types de
sondes, canaux d'alerte, le moteur de détection expliqué, la ligne de commande, les traductions et le dépannage.

---

## Le piloter depuis un agent (MCP)

Uptimer embarque un **serveur MCP** : Claude Code, Claude Desktop ou n'importe quel client MCP peut donc
l'interroger et agir sur ses réponses. Il est écrit en PHP comme le reste, parce que le serveur MCP ne doit pas
être la seule pièce qui réclame soudain Node.

```json
{
  "mcpServers": {
    "uptimer": {
      "command": "php",
      "args": ["/chemin/vers/uptimer/bin/mcp.php"],
      "env": { "UPTIMER_CONFIG": "/chemin/vers/uptimer/config.php" }
    }
  }
}
```

Vous pouvez alors demander simplement :

> *« Qu'est-ce qui est cassé sur le parc client ce matin ? »*
> *« Pourquoi la bêta Deezer ralentit ? Montre-moi la tendance sur 30 jours. »*
> *« Ajoute ces douze domaines, mais montre d'abord ce que tu créerais. »*
> *« La refonte de la recette Leboncoin est volontaire : réapprends sa référence et revérifie. »*

**Onze outils en lecture seule** sont exposés par défaut :

| Outil | Répond à |
|---|---|
| `status` | Est-ce que tout va bien ? Compteurs, uptime, temps de réponse, dernière passe |
| `tasks` | La liste de tâches : cause, pourquoi, quoi faire, la preuve, les correctifs disponibles |
| `list_monitors` | Toutes les sondes, avec une recherche insensible aux accents dans toutes les langues |
| `monitor_detail` | Un site en profondeur, avec l'audit des ressources et les décisions automatiques |
| `incidents` | Les interruptions d'une période, avec l'indisponibilité cumulée, pour répondre sur un SLA |
| `report` | Le rapport prêt à coller dans un ticket ou un e-mail client |
| `response_time_series` | La courbe, pour distinguer un pic d'une tendance |
| `web_vitals` | La vitesse ressentie : mesures de terrain et causes lues dans la page, séparées |
| `security_advisories` | Quels sites tournent sur une version couverte par un avis publié, le plus grave d'abord |
| `list_clients` | Chaque client, ses sites, leur état, et s'il consulte encore son espace |
| `security_target_check` | Cette adresse serait-elle refusée avant toute requête ? |

**Quatre de plus avec `--write`** : `check_now`, `apply_fix`, `set_enabled`, `add_sites`. La lecture seule est le
défaut délibérément : un agent qui explore ne doit pas pouvoir mettre une sonde en pause par accident.
`add_sites` fonctionne en `dry_run` par défaut, donc l'agent vous montre l'aperçu avant que rien n'existe.

---

## Fait pour qu'on lui fasse confiance

Un outil de surveillance qui vous ment est pire que pas d'outil du tout. La logique de détection est donc
testée contre de vraies pannes, et l'interface est testée dans un vrai navigateur.

```
php bin/selftest.php      305 contrôles   logique de détection, hors ligne, sans réseau
php bin/bench.php          44 contrôles   vraies pannes reproduites de bout en bout (dont badssl.com)
php bin/e2e.php           116 contrôles   parcours complet en HTTP réel, instance isolée
node bin/e2e-browser.mjs   57 contrôles   vrai Chromium : rendu, clavier, mobile, contrastes
php bin/chaos.php          33 contrôles   825 requêtes hostiles d'un utilisateur qui fait tout de travers
php bin/security.php       86 contrôles   OWASP Top 10, trois profondeurs, face à un site hostile local
php bin/mcp.php            n/a            serveur MCP pour les agents (27 des contrôles ci-dessus le testent)
php bin/deadcode.php       n/a            méthodes, fonctions, classes, CSS, msgid et fichiers inutilisés
php bin/i18n-audit.php     n/a            couverture des traductions, langue par langue
```

**641 contrôles, tous verts**, plus zéro code mort et un catalogue par défaut complet.

Deux suites méritent un mot.

**`bin/chaos.php`** joue un utilisateur qui écrit mal, clique partout, ne suit aucune consigne, envoie des
formulaires vides ou monstrueux, et cherche activement à casser : injection SQL, XSS, traversée de répertoire,
saisies de 5 Ko, tableaux là où une chaîne est attendue, verbes HTTP exotiques. Le contrat vérifié n'est pas
« ça marche » mais **« ça ne casse jamais »** : aucun 500, aucun message PHP qui fuit dans la page, rien de ce
que l'utilisateur a tapé réinjecté dans le HTML, et une base cohérente à l'arrivée.

**`bin/security.php`** audite à trois profondeurs, chaque contrôle étiqueté par sa référence OWASP :

| Profondeur | Ce qu'elle fait |
|---|---|
| **1, léger** | Configuration, secrets, drapeaux de cookie, surface exposée, surface de dépendances, revue statique des injections |
| **2, profond** | L'OWASP Top 10 en tests *actifs* sur une instance réelle isolée : accès non authentifié à chaque écran et chaque action d'API, accès direct aux fichiers source, traversée de chemin, CSRF sur chaque écriture, 11 charges d'injection SQL sur 5 paramètres, XSS réfléchie / stockée / par attribut, injection d'en-tête de réponse, fixation de session, verrou de force brute, invalidation à la déconnexion |
| **3, très profond** | Ce qui vise le collecteur lui-même : SSRF (un site surveillé qui redirige vers `file://`), XXE par un sitemap hostile, réponse de 40 Mo, contenu pathologique face aux expressions régulières, comparaison de jeton en temps constant, réponses de battement indiscernables, injection de formule dans le tableur, identifiants SQL dynamiques |

C'est exactement à ça qu'elles servent.

---

## Sous le capot

```
uptimer/
├── index.php · api.php · cron.php · beat.php · install.php    points d'entrée
├── src/
│   ├── Runner.php            le collecteur : curl_multi, relances, incidents, alertes
│   ├── Check/Css.php         les neuf signaux de mise en page + reconstitution console
│   ├── Check/Database.php    ~45 signatures de panne de base
│   ├── Check/Ssl.php         inspection du certificat en deux passes
│   ├── Detect/Cms.php        empreinte de la technologie
│   ├── Detect/Discovery.php  choix des pages + déduction de la chaîne de preuve
│   ├── Triage.php            transforme des états en liste de tâches
│   ├── Diagnose.php          23 causes → ce que ça veut dire, quoi faire
│   ├── Tune.php              seuils auto-ajustés + journal des décisions
│   ├── Heartbeat.php         le dead-man switch
│   └── I18n.php              10 langues, RTL, règles de pluriel
├── lang/                     un catalogue par langue
├── views/                    gabarits, aucun framework, aucune compilation
├── assets/                   un fichier CSS, un fichier JS, zéro dépendance
└── bin/                      les cinq suites de tests, la démo, l'audit i18n
```

**Contraintes de conception, assumées :**

- **Aucune dépendance, jamais.** Un outil de surveillance qui s'arrête parce qu'un paquet a été retiré n'est
  pas un outil de surveillance. Tout est en bibliothèque standard PHP.
- **Le mutualisé est une cible de premier rang.** Vérifications parallèles via `curl_multi`, agrégats
  journaliers pour que l'historique reste peu coûteux, travail réparti entre les passes de cron. Ça tourne sur
  o2switch, cPanel, Plesk, ou un VPS à 3 €.
- **SQLite par défaut**, MySQL quand on grandit. Les évolutions de schéma sont automatiques et non destructives.
- **Aucun emoji comme icône.** Un jeu d'icônes SVG dessiné à la main, trait constant.
- **L'interface est le produit.** Divulgation progressive partout : accordéons qui se souviennent, barre
  d'enregistrement collante, aide contextuelle derrière un `?`, palette de commandes, raccourcis clavier, et un
  mode Simple qui masque tout ce que vous n'allez pas toucher.
- **La sécurité est une suite, pas une promesse.** Mot de passe haché, CSRF sur chaque écriture, renouvellement
  de session à la connexion, verrou de force brute, `noindex` partout, un installeur qui se verrouille, un point
  de battement qu'on ne peut pas énumérer, et un garde-fou facultatif contre les plages privées pour la surface
  SSRF qu'un outil qui va chercher des URL a forcément. Le tout vérifié par `bin/security.php`, pas affirmé dans
  un README.

---

## Feuille de route

Le [backlog](BACKLOG.md) contient la recherche concurrentielle et les user stories derrière chaque décision.
À suivre :

- Résumé quotidien au lieu d'une alerte par évènement, pour ceux qui préfèrent un seul e-mail

---

## Contribuer

Les tickets et les pull requests sont bienvenus, en particulier :

- **Les traductions.** Neuf catalogues couvrent l'interface d'exploitation ; les textes d'aide longs retombent
  sur l'anglais. `php bin/i18n-audit.php --manquants=xx` liste exactement ce qui manque pour une langue.
- **Les signatures de détection.** Un CMS ou un framework dont on ne reconnaît pas encore la panne fait un bon
  ticket.
- **Les corrections du comparatif.** Si un concurrent a gagné une fonction, dites-le et ce sera corrigé.

Les règles de la maison : aucune dépendance, aucune compilation, le français pour les chaînes source (ce sont
les clés de traduction), un test pour tout ce qui peut régresser, et des commentaires qui expliquent *pourquoi*
plutôt que de répéter le code.

## Licence

MIT. Utilisez-le, vendez des services autour, forkez-le.

<div align="center">
<br>
<b>Uptimer</b>. Parce que « le site répond » n'a jamais été la question.
<br><br>
<sub>surveillance de sites web · monitoring auto-hébergé · outil de monitoring PHP · monitoring sur mutualisé ·
détection de CSS cassé · détection de base de données HS · surveillance de certificat SSL · surveillance de
tâches cron · dead man switch · page d'état · alternative à UptimeRobot · alternative à Uptime Kuma ·
monitoring pour agence web</sub>
</div>
