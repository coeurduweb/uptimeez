# Tout ce qu'UptimeEZ surveille

**La liste complète, couche par couche, avec ce que chaque contrôle attrape et ce qu'il coûte.**

[← Documentation](README.md) · [English version](../en/coverage.md)

---

## En une page

| Couche | Contrôles | Coût réseau |
|---|---|---|
| Disponibilité | Code HTTP, timings détaillés, redirections, relances | La requête de la page |
| Mise en page | 9 signaux sur CSS, scripts et polices, silhouette | Les ressources de la page, toutes les 15 min au plus |
| Données | 45 signatures, sonde CMS, chaîne de preuve, chaîne interdite | Aucun appel de plus, sauf la sonde CMS |
| Vitesse | Temps de réponse, fichiers bloquants, image du haut de page | Une requête HEAD sur une image |
| Échéances | Certificat, domaine, failles publiées | Une passe par jour, mise en cache |
| Silence | Battement dead-man | Aucun : c'est votre script qui appelle |

Tout est activé par défaut sur une sonde de type page, et chaque contrôle se coupe individuellement.

---

## 1. Est-ce que ça répond

**Code HTTP contre une plage attendue.** `200-299` par défaut, mais `200,301,404` est accepté : une page de
maintenance qui doit renvoyer 503 se surveille aussi bien qu'une page normale.

**Temps détaillés, à chaque mesure.** DNS, connexion, négociation TLS, premier octet, total. C'est ce découpage
qui distingue « le serveur est lent » de « la résolution DNS met deux secondes ».

**Chaîne de redirections.** Suivie jusqu'à la cible, avec détection de boucle. Si un site redirige durablement
(`http` vers `https`, ajout de `www`), UptimeEZ le signale et propose d'aligner la sonde sur la cible en un clic.

**Relances avant alerte.** Un hoquet réseau de deux secondes n'est pas une panne. Une sonde ne devient hors
service qu'après N échecs consécutifs, N étant réglable par sonde.

**Adresse réellement contactée.** L'IP est enregistrée à chaque mesure. Quand dix sites tombent ensemble sur la
même IP, UptimeEZ envoie **une** alerte groupée en disant que le problème est très probablement l'hébergement.

**Fenêtres de maintenance.** `02:00-04:00`, `mon-fri 02:00-04:00` ou `sat,sun 01:00-06:00` : pendant ces plages,
rien n'alerte.

---

## 2. Est-ce que la page est juste

C'est la partie qu'aucun outil d'uptime ne fait sans qu'on écrive du code. Le principe : un site peut répondre
`200 OK` en 180 ms et n'afficher qu'un mur de texte brut.

**Neuf signaux croisés**, sur chaque feuille de style, script et police déclarés dans la page :

| Signal | Ce qu'il attrape |
|---|---|
| Disponibilité de la ressource | Le 404 classique après déploiement |
| Type MIME et `nosniff` | Le serveur renvoie du HTML ou une trace PHP au lieu du CSS |
| Contenu mixte | Une ressource en `http` sur une page `https`, bloquée par le navigateur |
| CSP `style-src` | Une politique de sécurité qui bloque vos propres feuilles |
| Intégrité `integrity` | Une empreinte périmée : le navigateur refuse un fichier valide |
| Poids contre la référence apprise | La moitié du CSS disparue sans un seul 404 |
| Couverture des classes | Des classes du HTML sans règle correspondante, tolérant les échappements Tailwind |
| Media queries | La mise en page responsive envolée |
| Blocs en attente d'animation | Du contenu masqué par un script de révélation qui n'a pas chargé : une page *invisible* |

**Messages de console reconstitués.** UptimeEZ réécrit ce que le navigateur aurait affiché :
`net::ERR_ABORTED 404`, `Refused to apply style from …`, `Mixed Content: …`,
`Failed to find a valid digest in the 'integrity' attribute …`. Le ticket transmis au développeur contient déjà
la preuve.

**Silhouette avant/après.** La page est reconstruite en fil de fer à partir du HTML et du CSS réellement reçus,
et comparée à la silhouette de référence. Une image se comprend sans lecture, et c'est celle-là qu'on montre au
client. Elle est toujours annoncée comme une reconstitution, jamais comme une capture d'écran.
Voir [Détection](detection.md).

**Référence apprise, pas configurée.** L'empreinte est prise sur un état sain. Une refonte volontaire ne réveille
donc personne à trois heures du matin, et un bouton réapprend la référence quand le changement est voulu.

**Modification de contenu.** Une empreinte du texte visible : repère une publication passée en ligne, comme une
page défigurée. Bavard sur un site qui publie souvent, donc désactivé par défaut.

**Mot surveillé.** Un texte dont l'apparition ou la disparition compte : « Rupture de stock », « Erreur
fatale », le nom d'un produit qui doit rester en ligne.

**`noindex` oublié.** La balise `meta robots` et l'en-tête `X-Robots-Tag`. C'est le tueur silencieux du
référencement après une mise en ligne, et personne ne s'en aperçoit avant des semaines.

---

## 3. Est-ce que les données répondent

**Environ 45 signatures**, classées par moteur et par framework : WordPress, MySQL et MariaDB, PDO, mysqli,
Doctrine, Laravel, PrestaShop, Joomla, Drupal, SQLite, PostgreSQL. Elles couvrent la connexion impossible, la
table corrompue, la table absente du moteur, le quota disque atteint, l'index abîmé, les erreurs fatales et les
dépassements de mémoire ou de temps d'exécution.

**Sonde CMS qui traverse réellement la base.** Sur WordPress, l'API REST plutôt que la page d'accueil : un
accueil en cache peut s'afficher parfaitement pendant que la base est tombée.

**Chaîne de preuve, déduite du site.** Un texte qui ne peut venir que de la base. Par ordre de préférence : la
mention de copyright du pied de page, `og:site_name`, le titre de la page, la première entrée du menu, le H1.
La chaîne retenue est vérifiée, débarrassée des formulations passe-partout, et jamais déduite d'une page
d'erreur. Sa disparition alors que la page répond encore `200` signifie que la couche données est partie.

**Chaîne interdite.** L'inverse : un texte qui ne doit jamais apparaître. C'est ce que reprennent les mots-clés
inversés des autres outils lors d'un import.

**« Erreur affichée » n'est pas « site en panne ».** Une signature trouvée dans une page ne suffit pas à conclure :
un article de blog qui explique comment corriger « Error establishing a database connection » contient la phrase
sans être en panne, et pour une agence dont des clients sont hébergeurs ou développeurs, c'est la fausse alerte de
trois heures du matin garantie. Il faut donc au moins un autre signe, et un seul suffit : le serveur répond 5xx, ou
la page est courte — une vraie page d'erreur WordPress pèse quelques centaines d'octets — ou la chaîne de preuve a
disparu. Sans aucun des trois, le verdict est **dégradé** et dit ce qu'il voit : une erreur technique s'affiche sur
une page qui fonctionne. C'est un vrai défaut, visible par les visiteurs, mais ce n'est pas une panne.

**Ce qui n'a pas pu être lu ne prouve rien.** La lecture s'arrête à 3 Mo de HTML, pour ne pas épuiser la mémoire
d'un mutualisé. Au-delà, la fin de la page n'est pas lue : une chaîne de contrôle placée dans le pied de page ne
peut plus être trouvée, et son absence ne dit rien. Le verdict est alors « page trop volumineuse pour être vérifiée
en entier », jamais « la base de données ne répond plus ».

---

## 4. Est-ce rapide pour le visiteur

**Temps de réponse du serveur**, mesuré à chaque vérification. C'est le plancher de tout le reste : aucun
affichage ne commence avant. Le seuil visé est 800 ms.

**Seuil de lenteur auto-ajusté.** Calé sur le p95 mesuré de la sonde, multiplié par 1,8, avec une zone morte de
20 % et six heures de délai entre deux ajustements. Un site naturellement lent ne crie donc pas au loup, et un
site qui se dégrade vraiment est repéré. Chaque ajustement est consigné.

**Ce qui bloque le premier affichage**, avec le poids exact de chaque fichier : feuilles de style de l'en-tête,
scripts sans `defer` ni `async`. Le `media="print"` est correctement écarté.

**L'image du haut de page**, son poids réel obtenu par une seule requête HEAD, et deux défauts très fréquents :
l'image en `loading="lazy"` alors que c'est elle que le visiteur attend, et l'image qui dépasse 250 Ko.

**Les causes de décalage** : images sans `width` ni `height`, polices sans `font-display`.

**Les scripts tiers** chargés dans l'en-tête, comptés par domaine.

**Les trois mesures officielles** (LCP, INP, CLS) sur vos vrais visiteurs, avec une clé gratuite du Chrome UX
Report. Sans clé, aucun chiffre n'est inventé. Voir [Vitesse ressentie](vitesse.md).

---

## 5. Est-ce que ça va casser bientôt

**Certificat TLS, en deux passes.** Une lecture permissive pour établir les faits, une validation stricte façon
navigateur pour le verdict. Expiration, chaîne, autorité, correspondance du domaine, avec préavis réglable. Et le
cas qu'on oublie toujours : un certificat **pas encore valide**, que le navigateur refuse exactement comme un
certificat expiré. C'est une horloge serveur déréglée, ou un certificat émis d'avance et déployé trop tôt ; le
verdict le dit au lieu de rendre le message brut d'OpenSSL.

**Expiration du domaine**, par RDAP, une fois par jour. Un domaine expiré coupe le site *et* les e-mails, et peut
être racheté par un tiers.

**Failles publiées sur les versions détectées.** L'inventaire logiciel se lit dans le HTML déjà reçu, puis se
croise avec OSV.dev et api.wordpress.org. « Faille publiée » et « version en retard » ne sont jamais confondus.
Voir [Veille de sécurité](veille-securite.md).

**Battement dead-man.** Votre sauvegarde, votre export, votre tâche nocturne appelle UptimeEZ quand elle a fini.
C'est le **silence** qui déclenche l'alerte, la seule panne qu'aucune requête HTTP ne peut voir.

---

## Les cinq types de sondes

| Type | Ce qu'il vérifie | Réglages propres |
|---|---|---|
| **Page** | Une page HTML, avec tous les contrôles ci-dessus | Ressources, base, certificat, noindex, contenu |
| **API JSON** | Un point d'API | Chemin du champ, valeur attendue, en-têtes, corps, méthode |
| **Fichier** | Une ressource qui doit rester joignable et inchangée | Empreinte du contenu |
| **Mot-clé** | Un texte qui doit être là, ou ne jamais être là | Chaîne attendue, chaîne interdite |
| **Battement** | Une tâche qui doit s'exécuter | Fréquence attendue, tolérance |

Une sonde principale porte l'état du site ; les autres pages du même site sont regroupées avec elle.

---

## Ce qu'UptimeEZ ne surveille volontairement pas

Un outil qui prétend tout faire ne fait rien de bien. Ce qui est hors de son champ, et pourquoi :

- **Les ports TCP, le ping ICMP, le DNS, le SMTP.** UptimeEZ surveille en HTTP. Un port ouvert ne dit pas qu'un
  site fonctionne, et une sonde HTTP ne remplace pas un test de port : ce serait mentir sur ce qui est vérifié.
- **Les métriques serveur** (charge, disque, mémoire). Il faudrait un agent sur la machine. Zabbix fait ça très
  bien.
- **Les parcours scriptés** (se connecter, ajouter au panier, payer). Il faudrait un navigateur. Checkly fait ça
  très bien.
- **La trace applicative** dans votre code. New Relic fait ça très bien.
- **L'audit SEO complet.** UptimeEZ signale un `noindex` oublié parce que c'est un accident d'exploitation, pas
  parce qu'il fait de l'audit.

UptimeEZ fait une chose : **garder en vie les sites des autres, sans qu'un humain surveille un tableau de bord à
plein temps.**

---

[← Documentation](README.md) · [Détection](detection.md) · [Sondes](sondes.md) · [Vitesse](vitesse.md) · [Veille de sécurité](veille-securite.md)
