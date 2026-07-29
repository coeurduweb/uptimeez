# Documentation d'Uptimeez

**La surveillance de sites qui vous dit quoi faire.** Tout ce qu'il faut pour installer, exploiter et faire
confiance à Uptimeez.

[← Retour au projet](../../README.fr.md) · [English version](../en/README.md)

---

## Par où commencer

| Je veux… | Lire |
|---|---|
| Savoir tout ce qui est surveillé | **[Étendue](etendue.md)** : les cinq couches, contrôle par contrôle, et ce qui est hors champ |
| L'installer sur mon hébergement | **[Installation](installation.md)** : prérequis, mutualisé, cPanel/o2switch, MySQL |
| Reprendre un parc déjà surveillé ailleurs | **[Reprise](reprise.md)** : les cinq exports lus directement, ce qui passe et ce qui ne passe pas |
| Ajouter mes premiers sites et comprendre les écrans | **[Prise en main](prise-en-main.md)** : la visite en 5 minutes |
| Savoir ce que fait chaque option | **[Sondes](sondes.md)** : types, cadences, chaîne de preuve, tous les réglages |
| Comprendre *comment* il détecte | **[Détection](detection.md)** : les neuf signaux, les pannes de base, les certificats |
| Savoir pourquoi une page est lente | **[Vitesse ressentie](vitesse.md)** : ce qui est mesuré, ce qui est déduit, et pourquoi les deux ne se mélangent pas |
| Savoir si un site tourne sur une version vulnérable | **[Veille de sécurité](veille-securite.md)** : inventaire des versions, avis publiés, ce qui sort de chez vous |
| Recevoir les alertes là où je regarde | **[Alertes](alertes.md)**. Discord, Slack, e-mail, webhooks, heures calmes |
| Montrer quelque chose à un client | **[Rapports et page d'état](rapports.md)** |
| Donner à chaque client un accès à ses seuls sites | **[Mode agence](mode-agence.md)** : un lien par client, en lecture seule, révocable |
| L'interroger depuis Claude ou un autre agent | **[Serveur MCP](mcp.md)** : mise en place, les quinze outils, pourquoi la lecture seule par défaut |
| L'exploiter au quotidien | **[Exploitation](exploitation.md)** : cron, ligne de commande, sauvegardes, traductions, dépannage |

**Pressé ?** Trois commandes et vous surveillez :

```bash
php bin/demo.php            # un parc de démonstration pour visiter (mot de passe : demo1234)
php -S 127.0.0.1:8390 -t .  # ouvrez http://127.0.0.1:8390/
php bin/demo.php --purge    # puis repartez proprement avec install.php
```

---

## L'idée en une page

Uptimeez fait trois hypothèses sur vous.

**1. Vous vous occupez de sites qui appartiennent à d'autres.** Une panne n'est donc pas une abstraction, c'est un
appel téléphonique. L'écran d'accueil est pour cette raison une liste de tâches et non un tableau de bord : chaque
entrée dit ce qui est cassé, pourquoi c'est un problème, quoi faire, et porte les boutons qui le font.

**2. Vous n'avez pas le temps de configurer quoi que ce soit.** Uptimeez décide donc pour vous, et vous dit ce qu'il
a décidé. Collez une liste de domaines : il identifie la technologie, choisit des pages représentatives dans le
sitemap, déduit une chaîne de preuve du contenu du site, règle la cadence selon l'importance de la page, et cale le
seuil de lenteur sur le p95 mesuré. Chaque décision est consignée dans un journal lisible, et chacune peut être
reprise à la main : une valeur saisie gagne toujours.

**3. On ne vous réveillera que si c'est réel.** Une panne doit donc survivre aux relances avant de devenir un
incident, dix sites sur une même IP produisent une alerte au lieu de dix, les alertes « à surveiller » respectent
vos heures calmes, et une vraie panne passe toujours.

Tout le reste découle de ces trois points.

---

## Vocabulaire

Quelques mots reviennent partout, dans l'interface comme dans cette documentation.

| Mot | Sens |
|---|---|
| **Site** | Un domaine dont vous vous occupez. Regroupe une ou plusieurs sondes. |
| **Sonde** | Une chose vérifiée : une page, une API, un fichier, un mot-clé, ou un battement. |
| **Sonde principale** | La sonde de référence du site : en général l'accueil. Son état est celui du site. |
| **Chaîne de preuve** | Un texte qui ne peut venir que de la base. Sa présence prouve que le serveur web *et* la base répondent. |
| **Référence** | L'empreinte apprise des ressources d'une page saine : poids du CSS, nombre de règles, couverture des classes, media queries. |
| **Client** | Une personne pour qui vous surveillez des sites. Reçoit un lien vers un espace en lecture seule. |
| **Composant** | Un logiciel repéré sur un site : le cœur, une extension, un thème, avec sa version quand elle est lisible. |
| **Passe** | Une exécution du collecteur (`cron.php`). Chaque passe ne vérifie que les sondes dues. |
| **Incident** | Une période ininterrompue pendant laquelle une sonde était hors service. Ouvert à la panne, clos au rétablissement. |
| **Battement** | Une sonde qui attend d'être appelée au lieu d'appeler. C'est le silence qui alerte. |
| **Simple / Complet** | Le niveau de détail de l'interface. Simple ne montre que ce sur quoi on peut agir. |

---

## Où sont les choses

```
uptimeez/
├── config.php        votre configuration : à ne jamais versionner
├── data/             la base SQLite, le verrou du cron, le faux site de démo
├── lang/             les catalogues de traduction, un par langue
├── src/              le moteur
├── views/            les écrans
├── assets/           un fichier CSS, un fichier JS
└── bin/              les tests, la démo, l'audit i18n
```

Deux fichiers vous concernent : `config.php` (écrit par l'installeur et l'écran des réglages) et `data/` (votre
historique). Sauvegardez les deux. Tout le reste est du code que vous pouvez remplacer en bloc lors d'une mise à
jour.

---

## Aide et contributions

- Quelque chose de mal détecté ? Ouvrez un ticket avec l'URL : les faux positifs sont traités comme des bugs.
- Un CMS dont la panne n'est pas reconnue ? Bon ticket aussi, et souvent cinq lignes à ajouter.
- Une langue à compléter ? `php bin/i18n-audit.php --manquants=xx` liste exactement ce qui manque.

Les règles de la maison, si vous envoyez du code : aucune dépendance, aucune compilation, un test pour tout ce qui
peut régresser, et des commentaires qui expliquent *pourquoi*.
