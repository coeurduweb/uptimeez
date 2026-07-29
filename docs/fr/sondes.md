# Sondes

[← Prise en main](prise-en-main.md) · [Documentation](README.md) · [Détection →](detection.md)

Une sonde est une chose vérifiée. Un site en regroupe plusieurs, et l'état du site est celui de sa sonde la plus
préoccupante : sauf qu'une sonde en pause ne dégrade jamais un site qui va bien.

---

## Les cinq types

| Type | Ce qu'il fait | À utiliser pour |
|---|---|---|
| **Page web** | Contrôle complet : HTTP, TLS, chaîne de preuve, ressources de la page, `noindex`, empreinte du contenu | Presque tout |
| **API JSON** | Requête avec méthode, en-têtes et corps ; vérifie un chemin de champ et sa valeur | Points de santé, API internes |
| **Fichier** | Récupère un fichier précis et vérifie qu'il est bien servi | Un PDF, un flux, un script critique |
| **Mot-clé** | Une page, mais on ne s'intéresse qu'à la présence ou l'absence d'un texte | Contrôle léger sur une page lourde |
| **Battement** | Attend d'être appelé. **C'est le silence qui alerte** | Tâches cron, sauvegardes, imports nocturnes |

### Le battement, en détail

Celui-là est différent par nature : c'est le seul moyen de surveiller quelque chose qui ne présente aucune surface
HTTP. Créez une sonde battement, copiez la ligne qu'elle vous donne, et placez-la à la fin du script qui vous
importe :

```bash
curl -fsS --max-time 10 "https://votredomaine.fr/uptimeez/beat.php?k=JETON" > /dev/null
```

Ajoutez `&m=un+texte` pour joindre un mot au signal : un nombre de fichiers, une durée, un total de lignes. Si le
signal n'arrive pas dans l'intervalle plus la tolérance, un incident s'ouvre. Le signal suivant le clôt et envoie
l'avis de rétablissement.

Un jeton inconnu ou mal formé renvoie exactement le même `404` qu'un jeton inexistant : le point d'entrée ne peut
pas servir à énumérer les clés valides.

---

## Tous les réglages, et faut-il y toucher

Les champs marqués **auto** sont décidés pour vous, et redécidés au fil des mesures. Une valeur que vous saisissez
gagne toujours, définitivement.

### Identité et cadence

| Champ | Défaut | Y toucher quand |
|---|---|---|
| Nom | le nom du site | Il doit bien se lire dans une alerte |
| Adresse surveillée | telle que collée | Un domaine nu devient `https://`, les redirections sont suivies. Si HTTPS ne répond pas du tout, UptimeEZ retente en HTTP, le signale, et surveille quand même |
| Fréquence de vérification | **auto** selon l'importance | Vous voulez une page tarifs chaque minute. Plus court = détection plus rapide et plus de charge sur le site |
| Groupe | vide | Vous voulez filtrer le mur par client |
| Sonde active | activée | Décocher garde la sonde et son historique mais arrête les vérifications |

### La chaîne de preuve : le champ le plus utile de la page

| Champ | Défaut | Notes |
|---|---|---|
| Chaîne de contrôle | **auto**, déduite du contenu | Le texte qui prouve que le serveur web *et* la base répondent. Plusieurs variantes acceptées, séparées par `|` |
| Chaîne interdite | vide | Sa présence déclenche une alerte immédiate : « Site en maintenance », « Erreur de connexion » |

Pourquoi c'est important : sans elle, une page vide renvoyant `200` passerait pour valide. Avec elle, une panne de
base est détectée en une vérification alors que le code HTTP est impeccable.

UptimeEZ la déduit dans cet ordre de préférence : copyright du pied de page (qui vient des réglages du site, donc de
la base) → `og:site_name` → titre de la page → première entrée du menu → titre H1. Les formulations passe-partout
sont écartées (« tous droits réservés », « accueil »), et elle n'est jamais prise sur une page qui ressemble à une
page d'erreur. Si rien d'assez identifiant n'est trouvé, la sonde apparaît dans *À prévoir* pour que vous la
renseigniez à la main.

### Contrôles à effectuer

| Interrupteur | Défaut | Notes |
|---|---|---|
| Contrôler les ressources de la page | activé | CSS, scripts et polices : c'est ce qui détecte une mise en page cassée. [Comment ça marche](detection.md) |
| Détecter une base de données hors service | activé | ~41 signatures d'erreur, plus la chaîne de preuve |
| Surveiller le certificat TLS | activé | Validité, chaîne, correspondance du domaine, expiration |
| Prévenir avant l'expiration | 14 jours | Let's Encrypt se renouvelle seul ; ceci attrape les fois où il ne le fait pas |
| Alerter sur un `noindex` oublié | activé en production | Le tueur silencieux du SEO après une mise en ligne |
| Surveiller une mise à jour de contenu | désactivé | Me prévenir quand un texte apparaît (mise en ligne confirmée) ou disparaît |
| Signaler toute modification de contenu | désactivé | Empreinte du texte visible. Bavard sur un site qui publie souvent : à réserver aux sites figés |
| Figer la référence CSS actuelle | désactivé | À activer quand le design est stabilisé : la référence n'évoluera plus toute seule |

### Seuils

| Champ | Défaut | Notes |
|---|---|---|
| Seuil de lenteur | **auto** sur le p95 | Au-delà, la sonde passe « à surveiller » sans être déclarée hors service |
| Ajuster automatiquement | activé | Recalculé sur le p95 propre à cette sonde × 1,8, avec 6 h de temporisation et une zone morte de ±20 % pour ne jamais osciller |
| Délai maximum | 15 s | À augmenter pour un site réellement lent, plutôt que de baisser le seuil de lenteur |
| Relances avant alerte | 2 | Ce qui évite qu'un hoquet réseau de deux secondes déclenche une alerte |
| Chute de CSS tolérée | 35 % | Un cache vidé fait varier de quelques pour cent ; un déploiement raté fait chuter de moitié |

### Accès, maintenance et alertes (mode Complet)

| Champ | Notes |
|---|---|
| Identifiant / mot de passe HTTP | Pour un préprod derrière une authentification HTTP |
| User-Agent | À personnaliser si un pare-feu bloque le robot de surveillance |
| Ignorer les erreurs de certificat | Préprods en certificat auto-signé uniquement |
| Codes HTTP acceptés | Séparés par des virgules. Pour une page qui répond légitimement 401 ou 403 |
| Fenêtre de maintenance | `lun-ven 22:00-23:30`, `mar 02:00-04:00`. Les mesures continuent, seules les alertes se taisent |
| Canaux d'alerte | Vide = tous les canaux actifs globalement. Sinon `discord,mail` |

---

## Travailler sur beaucoup de sondes à la fois

La liste **Sondes** est faite pour les parcs : filtrez par nom, domaine ou technologie ; triez par état, lenteur ou
dernière vérification ; puis sélectionnez des lignes et appliquez une action de masse : mettre en pause, réactiver,
changer l'intervalle, relancer la détection automatique, supprimer.

Le filtre est insensible aux accents et à la casse dans toutes les langues : `casse` trouve `cassé`, `munchen`
trouve `München`.

---

## Supprimer ou mettre en pause

**Mettre en pause** garde tout et arrête les vérifications. Pour un site en refonte.

**Supprimer** retire la sonde, ses mesures, ses incidents et ses évènements, définitivement. La confirmation nomme
la sonde, pour qu'on ne supprime pas la mauvaise par automatisme.

Une suppression ne s'annule pas : c'est pourquoi le bouton vit dans un accordéon replié intitulé *action
irréversible*, et pourquoi la mise en pause est proposée juste à côté.
