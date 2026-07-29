# Vitesse ressentie par les visiteurs

**Uptimeez ne devine pas vos Core Web Vitals. Il mesure ce qu'il peut mesurer, il lit dans vos pages ce qui les
dégrade, et il dit lequel des deux il vous montre.**

[← Documentation](README.md) · [English version](../en/speed.md)

---

## Le problème, dit franchement

Les trois mesures officielles de Google (LCP, INP, CLS) viennent de vrais navigateurs Chrome, sur de vrais
visiteurs. Il n'existe aucun calcul honnête qui les remplace sans navigateur. Un outil en PHP qui afficherait
« LCP : 2,1 s » sans avoir lancé Chrome inventerait un chiffre, et vous le croiriez.

Uptimeez fait donc deux choses distinctes, avec deux vocabulaires distincts :

| Ce que c'est | Ce que ça vaut | Clé nécessaire |
|---|---|---|
| **Mesures de terrain** : LCP, INP, CLS sur vos visiteurs réels, via le Chrome UX Report | Ce sont les chiffres officiels, ceux qui comptent pour le classement | Oui, gratuite |
| **Causes lues dans la page** : temps de réponse mesuré, fichiers bloquants, image du haut de page, images sans dimensions, polices, scripts tiers | Ce sont des causes probables, et c'est ce sur quoi on agit | Non |

Les deux ne sont jamais mélangés dans une même phrase, et l'écran le rappelle noir sur blanc : « ce sont des
causes probables, rien ici n'est une mesure de navigateur ».

![Vitesse ressentie](../img/vitals.png)

---

## Ce qui fonctionne sans rien configurer

### Le temps de réponse du serveur

Mesuré par Uptimeez à chaque vérification, en millisecondes, sur le vrai réseau. C'est une mesure, pas une
estimation, et c'est le plancher de tout le reste : **le LCP ne sera jamais meilleur que le temps de réponse du
serveur.** Le seuil visé est 800 ms, au-delà de 1,8 s c'est mauvais.

### Ce qui bloque le premier affichage

L'audit des ressources télécharge déjà chaque feuille de style et chaque script de la page, avec leur poids exact.
Uptimeez en déduit ce qui bloque réellement le rendu :

- une feuille de style dans l'en-tête bloque le rendu, par construction ;
- une feuille en `media="print"` ne le bloque pas, et n'est donc pas comptée ;
- un script sans `defer` ni `async` dans l'en-tête bloque l'analyse du HTML ;
- un script en fin de corps de page ne bloque rien.

Le poids est compté par nature : « trois feuilles de style pèsent 203 Ko » ne mélange pas le JavaScript dedans.

### L'image du haut de page

C'est presque toujours elle que le LCP mesure. Uptimeez identifie la première image du corps qui n'est ni une
icône, ni un logo, ni un pixel de suivi, puis fait **une seule requête HEAD** dessus pour connaître son poids
réel, qui ne se lit nulle part dans le HTML.

Deux défauts très fréquents sont alors signalés :

- **l'image est en `loading="lazy"`** : le navigateur la charge en dernier alors que c'est celle que le visiteur
  attend. C'est l'erreur la plus courante et la plus facile à corriger ;
- **l'image dépasse 250 Ko** : sur un téléphone en 4G, ce seul fichier ajoute plus d'une seconde.

### Les décalages de mise en page

- **Images sans `width` ni `height`** : le navigateur ne peut pas réserver la place, le texte saute quand l'image
  arrive. Une image portant un `aspect-ratio` en style en ligne n'est pas comptée : la place est réservée.
- **Polices sans `font-display`** : le texte reste invisible pendant le téléchargement, puis apparaît d'un coup.

### Les scripts tiers

Le nombre de domaines tiers qui chargent du script dans l'en-tête. Un sous-domaine du site surveillé n'est pas un
tiers. Au-delà de quatre, c'est signalé : chacun ajoute une résolution DNS, une négociation TLS et du travail sur
le fil principal, ce qui retarde la réaction au premier clic.

**Chaque cause porte son remède.** Un constat sans conduite à tenir n'est qu'un reproche.

---

## Les mesures de terrain, avec une clé

### Créer la clé

1. Ouvrez la [console Google Cloud](https://console.cloud.google.com/), créez un projet ou prenez-en un.
2. Activez l'API **Chrome UX Report**.
3. Créez une **clé d'API** et copiez-la.
4. Collez-la dans **Réglages → Vitesse ressentie par les visiteurs**.

C'est gratuit, et la clé ne donne accès qu'à des données publiques d'audience agrégée. Aucun accès à vos sites,
aucun accès à votre Search Console.

### Ce que ça ajoute

Sur la fiche de chaque sonde, les trois mesures officielles avec leur verdict :

| Mesure | Seuil « bon » | Ce que le visiteur vit |
|---|---|---|
| Affichage du contenu principal (LCP) | 2,5 s | Le moment où la page paraît chargée |
| Réaction au premier clic (INP) | 200 ms | Le délai entre son geste et la réponse visible |
| Stabilité de la mise en page (CLS) | 0,1 | À quel point le contenu saute pendant le chargement |

**Le verdict d'ensemble retient le plus mauvais des trois.** C'est la règle de Google, et c'est la seule honnête :
une page dont la mise en page saute dans tous les sens n'est pas « globalement bonne » parce que son LCP va bien.

### Quand il n'y a pas de données

Le Chrome UX Report exige un échantillon suffisant. Une page peu visitée n'y figure pas. Dans ce cas, Uptimeez
interroge l'origine du site et vous le dit explicitement : « cette page n'a pas assez de trafic pour être mesurée
seule, les chiffres portent sur l'ensemble du site ». Si l'origine non plus n'a pas de données, aucun chiffre n'est
affiché. C'est une réponse, pas un échec.

Une métrique absente reste absente : elle ne devient jamais un zéro, qui se lirait comme un score parfait.

### Ce que ça coûte

Une interrogation par page et par jour, gardée 24 heures, plafonnée à trente par passe d'entretien. Le quota
gratuit du service est très au-dessus de ce qu'un parc d'agence consomme.

---

## Réglages

Dans **Réglages → Vitesse ressentie par les visiteurs** :

| Réglage | Effet |
|---|---|
| Récupérer les mesures de terrain | Coupe les interrogations du Chrome UX Report. L'analyse locale continue. |
| Clé du Chrome UX Report | Vide, seule l'analyse locale fonctionne. |
| Appareil de référence | Téléphone ou ordinateur. Le téléphone est le bon défaut : c'est là que les problèmes se voient. |

En ligne de commande :

```bash
php cron.php --vitals    # force une passe de mesures sans attendre 3 h du matin
```

Dans `config.php` :

```php
'vitals' => [
    'enabled'     => true,
    'crux_key'    => '',        // vide = analyse locale seulement
    'form_factor' => 'PHONE',   // ou DESKTOP
    'timeout_sec' => 10,
],
```

---

## Où ça apparaît

**Sur la fiche d'une sonde**, un bloc « Vitesse ressentie par les visiteurs » : les mesures de terrain si elles
existent, le temps de réponse mesuré, ce qui bloque le premier affichage, l'image du haut de page, puis la liste
des causes avec leur remède, classées par impact. La gravité se lit sur le bord de chaque cause, sans lire le
texte.

**Sur l'écran d'accueil**, une page dont le verdict de terrain est mauvais devient une tâche, avec la cause la plus
probable trouvée dans le HTML. Un chiffre sans cause ne fait agir personne.

**Depuis un agent**, l'outil MCP `web_vitals` rend les deux couches en JSON, séparées, avec les seuils appliqués.
Voir [Serveur MCP](mcp.md).

---

## Ce que cette fonction ne fait pas

- **Elle ne lance pas de navigateur.** Pas de Chrome sans tête, pas de Lighthouse, pas de Node. C'est ce qui
  permet à Uptimeez de tourner sur un hébergement mutualisé, et c'est aussi ce qui limite ce qu'il peut mesurer.
  Le choix est assumé.
- **Elle ne remplace pas PageSpeed Insights.** Pour auditer une page en profondeur avant une refonte, lancez
  Lighthouse. Uptimeez surveille en continu et vous prévient quand ça se dégrade, ce que Lighthouse ne fait pas.
- **Elle ne devine pas l'élément LCP exact.** Sans navigateur, on ne sait pas quel élément occupe le plus de
  place à l'écran. Uptimeez prend la première grande image du haut de page, ce qui est la bonne réponse dans la
  très grande majorité des pages, et il écrit « très probablement » plutôt que « c'est ».

---

## Dépannage

**Aucun bloc de vitesse sur une fiche.** L'analyse a lieu pendant l'audit des ressources, au maximum toutes les
quinze minutes par sonde. Le bouton *Vérifier maintenant* la force. Elle demande aussi que le contrôle des
ressources soit activé sur la sonde.

**Aucune mesure de terrain malgré la clé.** Trois causes possibles : la clé est invalide, l'API Chrome UX Report
n'est pas activée sur le projet Google Cloud, ou la page et son origine n'ont pas assez de trafic. Le mot
« mesuré » n'apparaît que quand une réponse a été obtenue, donc son absence est une information.

**Le poids de l'image du haut de page reste vide.** Le serveur ne renvoie pas d'en-tête `Content-Length` sur cette
image, ou la requête HEAD est refusée. Uptimeez n'invente pas le poids dans ce cas.

---

[← Documentation](README.md) · [Détection](detection.md) · [Sondes](sondes.md) · [Serveur MCP](mcp.md)
