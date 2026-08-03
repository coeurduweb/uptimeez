# Alertes

[← Détection](detection.md) · [Documentation](README.md) · [Rapports →](rapports.md)

Une alerte que personne ne lit est pire que pas d'alerte : elle vous apprend à ignorer le canal. Tout ce qui suit
existe pour que vos alertes vaillent la peine d'être lues.

---

## Les canaux

| Canal | Mise en place | Notes |
|---|---|---|
| **Discord** | Salon → Paramètres → Intégrations → Webhooks, collez l'URL | Le plus rapide. Mise en forme riche, lien cliquable vers la sonde |
| **Slack** | URL de webhook entrant | Idem |
| **Telegram** | Jeton du robot auprès de @BotFather, plus l'identifiant de la conversation | Deux valeurs et non une URL. L'identifiant n'est écrit nulle part dans l'application : écrivez au robot, puis lisez `chat.id` sur `api.telegram.org/bot{jeton}/getUpdates` |
| **Microsoft Teams** | URL entrante | Connecteur classique ou flux Power Automate, les deux acceptés. Un flux répond `202` et n'affiche rien tant que son modèle n'est pas relié à un champ |
| **SMS** | Identifiant Twilio, jeton, expéditeur et destinataires | Le seul canal facturé à l'envoi. Un segment, 155 caractères, titre et cause. À réserver à l'escalade |
| **E-mail** | Destinataires, expéditeur | `mail()` du serveur (parfait sur o2switch) ou SMTP direct avec TLS/SSL |
| **Webhook générique** | N'importe quelle URL | Envoie du JSON. Pour n8n, Make, une autre passerelle SMS, votre propre traitement |

Renseignez l'**adresse de l'installation** dans les réglages, sinon les alertes ne peuvent pas contenir de lien
cliquable vers la sonde concernée.

### L'escalade

Un rappel répète la même alerte aux mêmes personnes. **L'escalade change de destinataire** : après un délai que
vous choisissez, une seule fois, une panne non acquittée part vers une seconde liste de canaux. Quatre règles la
rendent utilisable plutôt que bruyante :

- **une seule fois par incident**, sinon un téléphone d'astreinte reçoit une alerte par minute et coupe ses
  notifications ;
- **les pannes seulement**, parce qu'une page lente n'a jamais justifié de réveiller une seconde équipe ;
- **l'acquittement l'annule**, ce à quoi le bouton d'acquittement a toujours servi ;
- **le délai compte depuis l'ouverture de l'incident**, pas depuis la dernière alerte, sinon une panne rappelée
  toutes les heures n'escaladerait jamais.

Réglages → *Escalade si personne n'acquitte*. À zéro, rien ne part. Une liste de canaux vide utilise tous les
canaux actifs ; une liste nommée en prend l'intersection, parce que nommer un canal éteint ne le rallume pas.

Puis appuyez sur **Tester** pour chaque canal. Un vrai message part par le vrai canal. Un canal non testé n'est pas
un canal.

### Contenu du webhook

```json
{
  "event": "down",
  "monitor": { "id": 12, "name": "Camping des Pins", "url": "https://camping-des-pins.fr/" },
  "state": "down",
  "reason": "CSS_BROKEN",
  "title": "La mise en page est cassée",
  "message": "Mise en page cassée : feuille de style en échec : …/cache/min/1/absent.css → HTTP 404",
  "since": "2026-07-28 18:24:11",
  "link": "https://votredomaine.fr/uptimeez/index.php?p=monitor&id=12"
}
```

`event` vaut `down`, `degraded`, `up` (rétablissement), `group` (panne groupée) ou `content` (évènement de contenu).

---

## Ce qui déclenche une alerte, et ce qui n'en déclenche pas

**Une sonde tombe.** Pas au premier échec : il faut *relances + 1* échecs d'affilée. Deux relances par défaut, ce
qui évite qu'un hoquet réseau de deux secondes réveille quelqu'un.

**Une sonde passe « à surveiller ».** Lenteur, certificat qui expire, CSS suspect, `noindex` oublié. Ces alertes
respectent les heures calmes, parce qu'aucune n'est une urgence.

**Une sonde se rétablit.** Activé par défaut : savoir que c'est fini sans avoir à vérifier, c'est la moitié de la
valeur.

**Un évènement de contenu.** Un mot surveillé est apparu ou a disparu, une page a changé, le CSS a été redéployé.

**Une panne groupée.** Voir ci-dessous.

Rien d'autre. Il n'y a pas de « rappel que tout va bien », pas de volume quotidien de bruit.

---

## Pannes groupées : le tueur de bruit

Quand trois sondes ou plus tombent sur la **même adresse IP** au cours d'une même passe, UptimeEZ envoie **une**
alerte qui nomme le serveur et liste les sites touchés, au lieu d'une alerte par site.

C'est la différence entre « mon hébergeur a eu un incident, j'ai reçu un message » et « mon hébergeur a eu un
incident, j'ai reçu quarante messages et j'ai raté celui qui comptait ».

L'IP vient de la vérification elle-même (`CURLINFO_PRIMARY_IP`) : cela fonctionne sans que vous déclariez la moindre
topologie, dépendance ou relation parent-enfant.

---

## Heures calmes

Format : `23:00-07:00`. La plage peut traverser minuit.

Pendant la fenêtre, **les alertes « à surveiller » sont retenues et regroupées**. Une vraie panne passe toujours :
personne ne devrait dormir pendant qu'un site est hors service, et un outil qui vous laisse configurer cela vous
aide à échouer.

---

## Fenêtres de maintenance

Par sonde, en mode Complet. Formats : `lun-ven 22:00-23:30`, `mar 02:00-04:00`, `sam 01:00-05:00`.

Dans la fenêtre, les mesures continuent : l'historique reste complet et honnête, mais les alertes se taisent. Pour
une sauvegarde nocturne qui sature le serveur, ou un déploiement hebdomadaire.

---

## Anti-répétition

| Réglage | Défaut | Effet |
|---|---|---|
| Rappel tant que ce n'est pas résolu | 60 min | Un incident ouvert réalerte à cet intervalle. Mettez 0 pour une seule alerte |
| Prévenir au rétablissement | activé | Un message quand c'est revenu |
| Prévenir sur « à surveiller » | activé | Désactivez pour n'être alerté que sur les vraies pannes |

Et la **prise en compte** : le bouton *Pris en compte* d'une carte de tâche stoppe les rappels sans clore
l'incident. Il dit « je l'ai vu, je m'en occupe » : l'historique reste juste, votre téléphone se calme.

---

## Garder des alertes utiles, en pratique

Une configuration qui marche bien pour un parc d'agence :

- **2 relances**, pour qu'un hoquet n'alerte jamais.
- **Heures calmes `23:00-07:00`**, pour qu'une lenteur nocturne attende le matin.
- **Rappel toutes les 60 minutes** sur les incidents ouverts.
- **Avis de rétablissement activés**, pour que personne ne poursuive un problème résolu.
- **Discord pour l'équipe, e-mail pour l'astreinte** : à définir par sonde en mode Complet, champ
  `canaux d'alerte`.
- **Modifications de contenu désactivées**, sauf sur les sites qui ne publient jamais, où un changement veut dire
  que quelqu'un est entré.

Si vous recevez encore des alertes sur lesquelles vous n'agissez pas, la correction est en amont : soit le seuil est
mauvais (laissez l'ajustement automatique s'en occuper), soit la sonde ne devrait pas exister.
