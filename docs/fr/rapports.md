# Rapports et page d'état

[← Alertes](alertes.md) · [Documentation](README.md) · [Exploitation →](exploitation.md)

Deux façons de montrer à quelqu'un d'autre ce que vous avez fait : un rapport que vous envoyez, et une page qu'il
peut ouvrir lui-même.

---

## Le rapport client

**Rapport** dans la navigation (mode Complet, ou directement depuis la palette). Choisissez un site et une période,
puis imprimez ou enregistrez en PDF.

![Rapport client imprimable avec disponibilité, bande journalière, courbe et incidents](../img/report.png)

Il contient :

- **La disponibilité** sur la période, et l'indisponibilité cumulée en heures et minutes lisibles ;
- **La bande journalière**, un carré par jour : vert pour une journée complète en ligne, orange pour un incident
  bref, rouge pour une interruption de plus de quinze minutes. Un client comprend cela en une seconde ;
- **Le temps de réponse** : moyenne et p95, avec la courbe ;
- **Le détail par page**, pour que « le site » ne soit pas une boîte noire ;
- **Le tableau des interruptions** : début, fin, durée, cause ;
- Un pied de page indiquant que le document a été produit automatiquement, sans intervention humaine.

La feuille de style d'impression retire la navigation, les boutons et les notifications : ce qui sort de
l'imprimante est un document, pas une capture d'écran d'application.

**Périodes :** 24 h, 7, 30, 90, 120, 180 et 365 jours. Au-delà de 40 jours, la courbe est reconstruite depuis les
agrégats journaliers, conservés indéfiniment : un rapport à un an reste donc juste même si les mesures brutes ont
été purgées.

---

## Le rapport mensuel qui part tout seul

Une agence qui envoie chaque mois un état de disponibilité à ses clients transforme
un travail invisible en travail visible. C'est ce qui permet de facturer la
surveillance au lieu de l'offrir.

**Rapport → Envoi automatique du rapport.** Trois réglages et c'est fait :

| Réglage | Ce qu'il fait |
|---|---|
| Envoyer automatiquement | Demande que le canal e-mail soit configuré et testé |
| Jour du mois | Le 1er par défaut. Un jour au-delà du 28 retombe sur le dernier jour d'un mois plus court |
| Objet du message | `{site}`, `{month}` et `{app}` sont remplacés |
| Destinataires par défaut | Utilisés pour les sites sans destinataire propre. Vide pour n'envoyer qu'aux clients nommés |

Puis, dans le même panneau, une ligne par site : les destinataires, un interrupteur,
la date du dernier envoi, et un bouton **Envoyer maintenant** qui n'attend pas la
date programmée.

**Chaque client reçoit ses sites, et rien d'autre.** Les destinataires se règlent par
site : un client ne voit jamais les chiffres d'un autre.

**L'envoi part une fois par mois, pas une fois par jour.** Il est marqué par une clé
de mois, donc la passe de cron qui tourne chaque minute ne peut pas produire de
doublon. Et un envoi qui échoue, parce que le serveur de messagerie était
momentanément indisponible, ne consomme pas le mois : il sera retenté le lendemain.

**Ce que contient le courrier.** Le chiffre de disponibilité et l'indisponibilité
cumulée, le temps de réponse et son p95, une bande jour par jour, une ligne par page
surveillée, la liste des interruptions avec cause et durée, et un avertissement si une
mise en page a dérivé.

Le courrier est composé pour des clients de messagerie et non pour un navigateur :
tableaux et styles en ligne, aucune feuille externe, aucune image distante, aucun SVG.
La comparaison visuelle d'une mise en page cassée reste sur le rapport en ligne, vers
lequel le courrier renvoie quand une page d'état publique est activée. Uptimeez ne met
jamais dans la boîte d'un client un lien qui demande le mot de passe de l'agence.

Forcer un envoi à la main :

```bash
php cron.php --report      # envoie tous les rapports dus, tout de suite
```

---

## La page d'état publique

**Réglages → Jeton de la page d'état publique.** Renseignez une chaîne aléatoire ; la page devient accessible à :

```
https://votredomaine.fr/uptimeez/index.php?p=status&token=VOTRE_JETON
```

Aucune session, aucun mot de passe, aucun accès au reste. Vous donnez ce lien à un client pour qu'il voie ses sites
sans avoir de compte dans votre outil de surveillance.

Laissez le jeton vide pour désactiver la page. Changez-le pour révoquer un lien qui a trop circulé.

La page montre l'état courant de chaque service surveillé et l'heure de dernière mise à jour. Elle respecte la
langue du visiteur : un client à Madrid obtient de l'espagnol, un client au Caire de l'arabe disposé de droite à
gauche.

---

## Le rapport à coller dans un ticket

Sur chaque carte de tâche : **Copier le rapport**. Il place dans le presse-papiers un résumé en texte brut :

```
# Camping des Pins : Hors service

URL surveillée : https://camping-des-pins.fr/
Constat le 28/07/2026 19:12 (fuseau Europe/Paris)
Technologie : WordPress

## Diagnostic
La mise en page est cassée
La page répond, mais les ressources qui la mettent en forme ne sont pas exploitables :
le visiteur voit une page nue, vide ou déstructurée.

Relevé technique : Mise en page cassée : feuille de style en échec : …/cache/min/1/absent.css → HTTP 404 [cache WP]

Erreurs que le navigateur signale :
  net::ERR_ABORTED 404 (Not Found)  …/wp-content/cache/min/1/absent.css

## Conduite à tenir
Ouvrez « Ressources de la page » ci-dessous : chaque fichier fautif y est listé avec sa
cause exacte. Après une refonte volontaire, réapprenez la référence.

## Chronologie
Début : 28/07/2026 18:24
En cours depuis 48 min
Vérifications en échec : 8

## Disponibilité
24 heures : 97,58 % (35 min hors service)
Temps de réponse moyen : 334 ms · p95 512 ms

Rapport produit par Uptimeez
```

Aucun HTML, aucun balisage à nettoyer. Ça part dans un ticket, un e-mail ou un message Slack tel quel : avec la
preuve déjà dedans, ce qui fait qu'un développeur agit au lieu de poser des questions.

---

## Export des incidents

**Incidents → Export CSV** vous donne les incidents de la période sous forme de tableur : sonde, début, fin, durée,
cause, nombre de vérifications en échec, alertes envoyées.

C'est votre justificatif de SLA. Si un contrat parle de 99,5 %, c'est le fichier qui prouve que vous l'avez tenu , 
ou celui qui vous dit quel hébergeur quitter.
