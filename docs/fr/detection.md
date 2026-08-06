# Comment fonctionne la détection

[← Sondes](sondes.md) · [Documentation](README.md) · [Alertes →](alertes.md)

Cette page explique ce qu'UptimeEZ regarde réellement. C'est important pour deux raisons : il faut faire confiance à
un verdict avant d'agir dessus, et il faut savoir pourquoi une fausse alerte est un bug qu'on veut connaître.

---

## Les 26 causes

Chaque verdict se résout en une cause, et chaque cause porte un titre, une explication et un remède, dans votre
langue.

| Code | Cause |
|---|---|
| `DNS` | Le nom de domaine ne se résout plus |
| `CONNECT`, `CONNECT_RESET` | Le serveur refuse la connexion |
| `TIMEOUT` | Le serveur ne répond pas dans le délai imparti |
| `SSL_EXPIRED` | Le certificat est expiré |
| `SSL_INVALID`, `SSL_HANDSHAKE` | Les navigateurs refusent le certificat |
| `SSL_SOON` | Le certificat expire bientôt |
| `HTTP_5XX` | Le serveur renvoie une erreur |
| `HTTP_404` | La page a disparu |
| `HTTP_403` | L'accès est interdit |
| `HTTP_401` | Une authentification est demandée |
| `HTTP_429` | Trop de requêtes |
| `HTTP_3XX` | Redirection inattendue |
| `REDIRECT_LOOP` | Boucle de redirection |
| `DB_DOWN` | La base de données ne répond plus |
| `APP_ERROR` | Erreur applicative PHP |
| `CSS_BROKEN` | La mise en page est cassée |
| `CSS_DEGRADED` | Ressources de rendu partiellement dégradées |
| `STRING_MISSING` | La chaîne de contrôle a disparu |
| `STRING_FORBIDDEN` | Une chaîne interdite est apparue |
| `JSON_INVALID`, `JSON_PATH`, `JSON_VALUE` | L'API ne renvoie pas la réponse attendue |
| `NOINDEX` | La page est en `noindex` |
| `SLOW` | Le temps de réponse dépasse votre seuil |
| `HEARTBEAT_LATE` | Le signal attendu n'est pas arrivé |

---

## Mise en page cassée : les neuf signaux

Une page qui répond `200` peut être inutilisable. UptimeEZ récupère le HTML, extrait chaque feuille de style, script
et police, les récupère aussi (six ressources par passe au maximum, pour rester poli), et croise neuf signaux.

### 1. Disponibilité

Chaque ressource est demandée. Un `404`, un `403` ou un timeout sur une feuille de style est décisif : le navigateur
aurait affiché la page sans style. C'est la panne classique d'après déploiement : un chemin de cache qui n'existe
plus, une empreinte de build qui a changé, un fichier non envoyé.

### 2. Type MIME et `nosniff`

Un serveur qui renvoie `text/html` pour un fichier `.css` renvoie en général une page d'erreur ou une trace PHP.
Avec `X-Content-Type-Options: nosniff`, que la plupart des serveurs durcis envoient désormais, le navigateur
refuse le fichier purement et simplement. UptimeEZ le signale comme bloqué, parce que c'est ce qui se passe en vrai.

### 3. Contenu mixte

Une ressource en `http://` appelée depuis une page en `https://` est bloquée silencieusement par tous les
navigateurs modernes. Aucune erreur dans le HTML, rien dans le journal du serveur. Seul le visiteur voit une page
cassée.

### 4. Politique de sécurité du contenu

Si la CSP de la page comporte un `style-src` qui exclut l'origine servant réellement votre feuille de style, le
navigateur la refuse. Cela arrive après un durcissement de sécurité que personne n'a relié au CSS.

### 5. Intégrité des sous-ressources

Un attribut `integrity` dont l'empreinte ne correspond plus au fichier fait rejeter par le navigateur une feuille de
style parfaitement valide et parfaitement disponible. Cela arrive chaque fois qu'un fichier est régénéré sans mettre
l'empreinte à jour.

### 6. Volume comparé à la référence apprise

UptimeEZ se souvient de ce à quoi ressemble un état sain : poids total des feuilles de style et nombre de règles CSS.
Une chute au-delà du pourcentage toléré (35 % par défaut) signifie que le CSS a été remplacé par quelque chose de
beaucoup plus petit : un build tronqué, un cache en cours de purge, un minifieur trop zélé.

### 7. Couverture des classes

Il relève les classes utilisées dans le HTML et vérifie combien trouvent une règle correspondante dans le CSS
chargé. Une page dont les classes n'ont presque aucune règle est une page sans style, même si chaque fichier a
répondu `200`. Les noms de classes échappés de Tailwind (`md:flex`, `w-1/2`) sont gérés, donc les sites en
utility-first ne déclenchent rien.

### 8. Media queries

Plus aucune media query dans tout le CSS signifie que la mise en page responsive a disparu. Le bureau peut avoir
l'air correct alors que chaque visiteur sur téléphone voit une page cassée.

### 9. Contenu en attente d'animation

Les constructeurs de pages modernes masquent des blocs (`opacity: 0`) et les révèlent avec un script. Si ce script
échoue, la page répond `200`, le CSS est bon, et le contenu est *invisible*. UptimeEZ compte les blocs restés en
attente de révélation et le signale : une panne qui paraît parfaite sous tous les autres angles.

### La silhouette : montrer au lieu de décrire

Un chiffre ne clôt pas une discussion avec un client. Une image, si.

À chaque analyse, UptimeEZ reconstruit donc une **silhouette** de la page : la
structure des blocs lue dans le HTML, mise en page selon ce que le CSS réellement
chargé permet de faire. Titres, paragraphes, images, boutons, colonnes. Il garde
la silhouette d'un état sain comme référence, et compare l'actuelle avec elle.

Quand une feuille de style tombe, la silhouette change exactement comme la page
change : plus de conteneur centré, plus de colonnes, tout empilé sur toute la
largeur, images démesurées. C'est précisément ce que le visiteur a sous les yeux.

La fiche de la sonde montre les deux côte à côte avec l'écart mesuré, et tout
site au-delà de 20 % d'écart apparaît dans le rapport client, sous « Ce que voit
le visiteur ».

**Ce n'est pas une capture d'écran, et l'interface le dit.** UptimeEZ n'exécute
aucun navigateur : c'est ce qui lui permet de vérifier des centaines de sites
depuis un mutualisé. La silhouette est une reconstruction fonctionnelle, et pour
cet usage elle suffit.

L'écart se mesure sur cinq traits que le visiteur perçoit : le contenu est-il
encore tenu dans un conteneur centré, y a-t-il encore des colonnes, la page
s'est-elle beaucoup allongée, la variété des types de blocs subsiste-t-elle, tout
occupe-t-il désormais la largeur. Au-delà de 35 %, un visiteur voit une autre
page.

**Une note de sécurité, parce qu'elle compte ici.** La silhouette est un SVG
injecté directement dans la page. Rien de ce que contrôle le site surveillé n'y
entre jamais : le rendu n'émet que des nombres et une palette fixe, aucun texte,
aucun nom de classe, aucun attribut venu du site. La suite de tests le vérifie
avec du HTML volontairement hostile.

### Ce que vous en obtenez

Au-delà du verdict, la fiche de la sonde reconstitue **ce que la console du navigateur aurait affiché** :

```
net::ERR_ABORTED 404 (Not Found)   …/wp-content/cache/min/1/absent.css
Refused to apply style from '…/theme.css' because its MIME type ('text/html') is not a
  supported stylesheet MIME type, and strict MIME checking is enabled.
Mixed Content: The page at 'https://…' was loaded over HTTPS, but requested an insecure
  stylesheet 'http://…'. This request has been blocked.
```

Ce bloc se colle tel quel dans un ticket, et c'est pour cette raison qu'un développeur vous croit.

### Pourquoi il ne crie pas au loup

- La référence est **apprise sur les états sains**, pas configurée.
- Un verdict exige un **signal décisif** (feuille de style manquante ou bloquée) ou **plusieurs signaux faibles
  convergents**.
- Les ressources tierces sont pondérées différemment des vôtres : un CDN de polices lent dégrade, il ne casse pas.
- Une refonte volontaire est à un bouton de devenir la nouvelle norme (*Réapprendre la référence*).
- Quand l'analyse n'a pas eu lieu sur une passe, le verdict précédent est repris plutôt que remis à zéro : pas de
  battement entre « cassé » et « conforme ».

---

## Base de données tombée derrière un 200

Trois signaux indépendants, parce que chacun pris seul peut être trompé.

**1. Les signatures d'erreur.** Une quarantaine de motifs couvrant WordPress (« Error establishing a database
connection »), Laravel, Doctrine, PDO, Symfony et MySQL brut (« Too many connections », « Access denied for user »,
« MySQL server has gone away »), plus les pannes au niveau PHP : mémoire épuisée, exception non interceptée, disque
plein, SQLite verrouillée, connexion Redis en échec.

**2. La chaîne de preuve.** Un texte qui ne peut venir que de la base. S'il disparaît alors que la page répond
encore `200`, la couche données est tombée. C'est ce qui attrape la panne polie : le CMS qui sert une coquille en
cache avec un contenu vide.

**3. Une sonde CMS qui touche vraiment la base.** Sur WordPress, `/wp-json/` traverse la base, contrairement à une
page d'accueil entièrement mise en cache. UptimeEZ ajoute cette sonde automatiquement quand il détecte WordPress.

---

## Certificats : deux passes

Une seule lecture TLS ne peut pas répondre aux deux questions qui vous intéressent. UptimeEZ en fait deux.

**Passe permissive**, se connecte en acceptant tout, lit le certificat et en extrait les faits : sujet, émetteur,
expiration, noms alternatifs. Cela fonctionne même sur un certificat expiré ou auto-signé, c'est-à-dire exactement
quand vous avez besoin des détails.

**Passe stricte** : se connecte comme un navigateur, en vérifiant le pair et le nom d'hôte. Son seul rôle est le
verdict : un visiteur verrait-il un écran d'avertissement ?

Ensemble, elles distinguent « expire dans 3 jours » de « expiré hier », de « valide mais ne couvre pas ce domaine »,
de « autorité inconnue » : quatre situations, quatre remèdes différents.

---

## Temps de réponse et seuil auto-ajusté

Chaque vérification enregistre le DNS, la connexion, le TLS, le premier octet et le temps total.

Un seuil fixe se trompe deux fois : trop bas pour un site lourd par nature (fausses alertes en continu), trop haut
pour un site rapide (une vraie dégradation passe inaperçue). Le seuil est donc dérivé du **p95 propre au site ×
1,8**, plancher à 1,2 s et plafond à 20 s, avec :

- un minimum de 20 mesures avant tout ajustement ;
- une temporisation de 6 heures entre deux ajustements ;
- une zone morte de ±20 %, pour qu'un changement insignifiant ne réécrive jamais le réglage.

Chaque ajustement est écrit dans le journal des décisions avec son motif. Et dès que vous saisissez une valeur à la
main, l'ajustement automatique s'arrête pour cette sonde.

---

## Expiration du domaine

Une requête RDAP par jour et par domaine enregistrable. RDAP est le successeur de WHOIS et renvoie du JSON
structuré : il n'y a rien à gratter. Un domaine qui expire apparaît dans *À prévoir* 45 jours à l'avance, assez
tôt pour compter et assez tard pour ne pas être du bruit.

---

## Ce qu'UptimeEZ ne fait volontairement pas

- **Il n'exécute pas de navigateur.** Pas de JavaScript, pas de rendu. C'est ce qui lui permet de vérifier des
  centaines de sites depuis un mutualisé. Les neuf signaux sont la façon d'obtenir un verdict de rendu sans moteur
  de rendu.
- **Il ne teste pas de parcours utilisateur.** Pas de formulaire rempli, pas de connexion, pas de tunnel d'achat.
  Checkly fait ça, et le fait bien.
- **Il ne surveille pas de serveurs.** Ni CPU, ni RAM, ni disque sur vos machines. C'est le métier de Zabbix.
