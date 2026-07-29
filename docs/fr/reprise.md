# Reprendre un parc surveillé ailleurs

**Déposez l'export de votre outil actuel. Uptimer le reconnaît, vous montre ce qu'il va créer, et vous dit ce
qu'il ne peut pas reprendre.**

[← Documentation](README.md) · [English version](../en/migrate.md)

---

## Le vrai frein au changement

Ce n'est pas le prix, c'est la soirée à ressaisir quarante sondes. Avec les cadences, les mots-clés, les sites en
pause. Personne n'a envie de le faire, donc personne ne change d'outil, même quand l'outil ne convient plus.

Cinq exports sont lus directement, sans rien choisir dans un menu :

| Outil | Ce qu'il faut fournir |
|---|---|
| **UptimeRobot** | Réponse de l'API `getMonitors`, ou l'export CSV du tableau de bord |
| **Uptime Kuma** | Réglages → Sauvegarde → Exporter (JSON) |
| **Better Stack** | Réponse de l'API `/api/v2/monitors` |
| **Pingdom** | Réponse de l'API `/api/3.1/checks` |
| **Site24x7** | Réponse de l'API `/api/monitors` |

Et un **CSV générique** pour tout le reste : il suffit d'un en-tête contenant `url`, `website`, `hostname` ou
`adresse`. Les colonnes `nom`, `intervalle`, `mot-clé` et `actif` sont reconnues si elles sont là, en français
comme en anglais, dans n'importe quel ordre.

![Aperçu d'une reprise](../img/import-reprise.png)

---

## Comment ça se passe

1. **Écran Ajouter des sites** → *Ou déposez l'export de votre outil actuel*.
2. Uptimer reconnaît le format **au contenu**, jamais au nom du fichier : un export renommé fonctionne, et un
   fichier appelé `uptimerobot.json` qui n'en est pas ne trompe personne.
3. L'aperçu s'affiche : ce qui sera créé, avec quelle cadence, quelle chaîne de contrôle, et ce qui ne peut pas
   être repris.
4. Vous validez. La détection de technologie, le choix des pages et la chaîne de preuve se font ensuite, site par
   site, exactement comme pour un ajout manuel.

Rien n'est créé avant votre validation.

---

## Ce qui est repris

| Réglage | Comportement |
|---|---|
| Adresse | Reprise telle quelle. Pingdom stocke le nom d'hôte et le chemin séparément : l'adresse est reconstruite, chiffrement compris. |
| Nom | Repris. À défaut, Uptimer en déduit un du domaine. |
| Cadence | **Reprise telle quelle**, et signalée comme telle dans l'aperçu. Une cadence d'une minute chez le voisin reste une minute ici : c'est un choix que quelqu'un a fait. |
| Mot-clé attendu | Devient la chaîne de contrôle. |
| Mot-clé qui déclenche l'alerte | Devient une **chaîne interdite**, ce qui n'est pas la même chose. Voir ci-dessous. |
| Sonde en pause | **Créée en pause.** La réactiver serait décider à votre place. |
| Codes HTTP acceptés | Repris quand l'export les donne. |
| Méthode, relances, délai | Repris quand l'export les donne (Uptime Kuma les fournit). |

### Le sens du mot-clé, qui se perd facilement

Les outils n'emploient pas la même convention, et se tromper inverse l'alerte :

- **UptimeRobot** : `keyword_type` valant « exists » veut dire « alerter si le texte est là ». C'est donc une
  chaîne **interdite** chez nous, pas une chaîne de contrôle.
- **Uptime Kuma** : `invertKeyword` fait la même bascule.
- **Better Stack** : le type `keyword_absence` alerte quand le texte est présent.
- **Pingdom** : `shouldcontain` est une chaîne de contrôle, `shouldnotcontain` une chaîne interdite.
- **Site24x7** : `matching_keyword` est attendu, `unmatching_keyword` est interdit.

Uptimer respecte chaque convention. C'est vérifié par les tests, pour chacun des cinq outils.

---

## Ce qui n'est pas repris, et pourquoi

**Les sondes sans équivalent.** Un port TCP, un ping ICMP, une résolution DNS, un test SMTP : Uptimer surveille en
HTTP. Ces sondes apparaissent dans une liste, avec la raison, et ne sont pas créées. Un import qui perd six sondes
sur quarante sans le dire est pire qu'un import qui refuse.

**Les battements.** Un dead-man switch dépend d'une URL secrète que le script appelé doit connaître. La reprendre
n'aurait aucun sens : il faut créer la sonde ici pour obtenir une nouvelle URL, puis la coller dans le script.
Voir [Sondes](sondes.md).

**L'historique de mesures.** C'est le point le plus important, et le plus tentant à ignorer. Ces mesures ont été
prises par un autre outil, avec d'autres seuils, depuis un autre réseau, à une autre fréquence. Les afficher comme
les siennes serait un mensonge : un « 99,98 % » repris de Pingdom ne dirait rien de ce qu'Uptimer aurait mesuré.
Le compteur de disponibilité repart donc de zéro, et c'est écrit avant l'import.

**Les contacts d'alerte.** Les canaux se configurent une fois pour toute l'installation, pas par sonde. Voir
[Alertes](alertes.md).

---

## Migrer sans coupure

La bonne façon de changer d'outil n'est pas de couper l'ancien :

1. Reprenez le parc dans Uptimer et laissez la tâche planifiée tourner.
2. Gardez l'ancien outil actif quelques jours, alertes comprises.
3. Comparez : sur un incident réel, les deux doivent alerter. Si Uptimer voit une panne que l'autre a manquée,
   c'est le cas le plus fréquent, et souvent une mise en page cassée ou une base tombée derrière un code 200.
4. Coupez l'ancien quand vous n'avez plus de doute.

---

## Limites et bornes

| Borne | Valeur |
|---|---|
| Taille de fichier acceptée | 4 Mo |
| Sondes reprises en une fois | 500 |
| Formats de fichier | Texte : JSON, CSV, TSV. Un fichier binaire est écarté avant analyse. |

Le contenu déposé est lu comme du texte et jamais exécuté, le nom du fichier ne sert à rien, et l'écran d'import
n'est pas atteignable sans être authentifié. Ces trois points sont vérifiés par la suite de sécurité.

---

## Dépannage

**« Format non reconnu ».** Le fichier n'est ni un des cinq exports, ni un CSV avec une colonne d'adresses.
Solution la plus simple : ouvrez-le dans un tableur, gardez une colonne `url` et une colonne `nom`, exportez en
CSV.

**Toutes les cadences sont à cinq minutes.** L'export ne contenait pas d'intervalle : c'est la valeur choisie sur
l'écran d'import qui s'applique. L'aperçu ne portait alors pas la mention « reprise de l'export ».

**Une sonde importée n'est pas surveillée.** Elle était en pause dans l'export, et elle a été créée en pause.
L'aperçu l'annonçait avec la mention « à créer, en pause ». Réactivez-la depuis sa fiche.

**Des sondes en double.** Une adresse déjà surveillée n'est jamais recréée : l'aperçu la marque « déjà présente ».
Repasser le même export ne crée rien.

---

[← Documentation](README.md) · [Prise en main](prise-en-main.md) · [Sondes](sondes.md) · [Alertes](alertes.md)
