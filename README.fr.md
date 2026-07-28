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
| Base de données tombée derrière un 200 | ✅ signatures + chaîne de preuve | ⚠️ mot-clé manuel | ⚠️ assertion manuelle | ⚠️ mot-clé manuel | ⚠️ mot-clé manuel | ⚠️ à construire | ⚠️ assertion manuelle |
| Chaîne de preuve déduite automatiquement | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Alerte sur un `noindex` oublié | ✅ | ❌ | ⚠️ script | ❌ | ❌ | ❌ | ⚠️ script |
| Ajout en masse avec détection du CMS | ✅ collez n'importe quoi | ⚠️ CSV, sans détection | ❌ orienté code | ⚠️ CSV | ❌ un par un | ❌ | ❌ |
| Aperçu avant création des sondes | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Seuil de lenteur auto-ajusté | ✅ sur le p95 | ❌ fixe | ❌ fixe | ❌ fixe | ❌ fixe | ⚠️ à construire | ⚠️ formules payantes |
| Journal des décisions de l'outil | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Accueil = liste de tâches avec correctifs | ✅ | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord | ❌ tableau de bord |
| Annulation de chaque action | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pannes regroupées par IP de serveur | ✅ automatique | ❌ | ❌ | ⚠️ à configurer | ❌ | ✅ topologie | ⚠️ à configurer |
| Battement dead-man (cron, sauvegardes) | ✅ | ✅ | ⚠️ | ✅ | ✅ push | ✅ | ⚠️ |
| Rapport client imprimable | ✅ intégré | ⚠️ formules payantes | ❌ | ✅ | ❌ | ⚠️ | ✅ |
| Rapport mensuel envoyé à chaque client | ✅ | ❌ | ❌ | ⚠️ interne seulement | ❌ | ❌ | ⚠️ interne seulement |
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

**Huit outils en lecture seule** sont exposés par défaut :

| Outil | Répond à |
|---|---|
| `status` | Est-ce que tout va bien ? Compteurs, uptime, temps de réponse, dernière passe |
| `tasks` | La liste de tâches : cause, pourquoi, quoi faire, la preuve, les correctifs disponibles |
| `list_monitors` | Toutes les sondes, avec une recherche insensible aux accents dans toutes les langues |
| `monitor_detail` | Un site en profondeur, avec l'audit des ressources et les décisions automatiques |
| `incidents` | Les interruptions d'une période, avec l'indisponibilité cumulée, pour répondre sur un SLA |
| `report` | Le rapport prêt à coller dans un ticket ou un e-mail client |
| `response_time_series` | La courbe, pour distinguer un pic d'une tendance |
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

- Import depuis un sitemap et depuis un CSV
- Résumé quotidien au lieu d'une alerte par évènement, pour ceux qui préfèrent un seul e-mail
- Core Web Vitals sur les pages qui comptent
- Veille de vulnérabilités WordPress sur les versions détectées
- Rapport client mensuel envoyé automatiquement

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
