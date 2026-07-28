# Mode agence : un lien par client

**Un client, c'est un nom et un lien. Le lien ouvre une page où il voit ses sites, et rien d'autre.**

[← Documentation](README.md) · [English version](../en/agency-mode.md)

---

## Le problème

Vous surveillez trente sites qui appartiennent à douze personnes. Chacune veut savoir si le sien va bien. Aucune
n'a à voir les vingt-neuf autres.

Les outils du marché répondent à ça par des comptes utilisateurs, des rôles et des permissions : trois écrans de
configuration, un mot de passe à transmettre par client, et un « j'ai perdu mon mot de passe » un dimanche soir.

Uptimer répond autrement. Vous créez un client, vous cochez ses sites, vous copiez son lien. C'est fini.

![Écran des clients](../img/clients.png)

---

## Ce que le client voit

Une page, sans compte et sans mot de passe :

- une bande en haut qui dit **tout fonctionne** ou **un de vos sites ne répond pas** ;
- un bloc par site : état, courbe des 24 dernières heures, disponibilité sur 30 jours ;
- les interruptions récentes, avec leur date, leur durée et si c'est rétabli.

![Espace client](../img/client-space.png)

Ce qu'il ne voit pas : vos autres clients, vos réglages, vos seuils, vos décisions automatiques, le nom de vos
outils. Il n'y a aucun bouton sur cette page, et le mot « sonde » n'y apparaît pas.

C'est lisible sur un téléphone, parce que c'est là qu'un client ouvre un lien reçu par e-mail.

---

## Le lien vaut mot de passe

Autant le dire franchement : le lien contient un jeton de 32 caractères hexadécimaux tirés au hasard. Quiconque
l'a peut voir la page. C'est le même compromis que la page d'état publique, et c'est assumé : un client qui doit
créer un compte pour voir si son site marche ne le fera pas.

Ce qui est fait pour que ce compromis reste tenable :

| Mesure | Effet |
|---|---|
| Jeton tiré de `random_bytes` | 128 bits, non devinable, jamais dérivé du nom du client |
| Page en `noindex, nofollow, noarchive` | Un moteur qui tomberait sur le lien ne le publiera pas |
| `Referrer-Policy: no-referrer` | Les sites que la page met en lien ne reçoivent pas le jeton |
| `Cache-Control: private, no-store` | Pas de copie dans un cache partagé |
| Aucune écriture atteignable | Les actions vivent derrière l'authentification, pas derrière le jeton |
| Changement du lien en un clic | Le lien ayant circulé trop loin est mort immédiatement |
| Accès fermé sans rien perdre | Le lien renvoie une page introuvable, l'historique reste intact |

Un lien inconnu, un lien mal formé et un lien fermé donnent **exactement la même réponse** : impossible de
deviner, en tâtonnant, qu'un client existe.

Le cloisonnement, enfin, n'est pas une affaire d'affichage. Chaque lecture de l'espace filtre sur
`client_id`, et aucun identifiant pris dans l'URL n'entre dans ces requêtes : ajouter `&client_id=7` ou `&site=3`
au lien ne change rien à ce qui s'affiche. C'est vérifié par les suites de tests, y compris avec des jetons
hostiles.

---

## Mise en place

### Créer un client

Écran **Clients** → *Ajouter un client*. Un nom suffit. L'adresse de contact est facultative et ne sert qu'au
rapport mensuel.

### Rattacher ses sites

Dans le bloc *Réglages* du client, cochez ses sites. Un site n'appartient qu'à un seul client : ceux déjà pris
apparaissent verrouillés, avec le rappel qu'ils sont rattachés ailleurs. Décocher un site le laisse simplement
sans client, il ne disparaît pas.

### Si vos sites sont déjà groupés

L'import permet de saisir un groupe. Si vous l'avez utilisé, le bouton **Reprendre les groupes existants** crée
un client par groupe et rattache les sites en une fois. Rien n'est écrasé : un site déjà rattaché reste où il
est, un client du même nom est réutilisé plutôt que dupliqué, et repasser le bouton ne crée aucun doublon.

### Envoyer le lien

Le champ *Lien à envoyer au client* se sélectionne d'un clic. Renseignez d'abord **Réglages → Adresse de cette
installation**, sinon le lien commencera par `https://votre-adresse-uptimer`.

---

## Ce que ça change sur le reste

**Le rapport mensuel hérite de l'adresse du client.** Un site sans destinataire propre utilise l'adresse de
contact de son client. C'est ce qui évite de ressaisir la même adresse sur ses huit sites. Le réglage du site,
quand il existe, gagne toujours. Voir [Rapports](rapports.md).

**L'agent MCP sait répondre par client.** L'outil `list_clients` donne les clients, leurs sites, leur état et
s'ils consultent encore leur espace, ce qui permet de demander « quel client dois-je appeler en premier ? ». Le
lien, lui, n'est jamais renvoyé : il ouvre une page sans authentification, il n'a rien à faire dans une
conversation. Voir [Serveur MCP](mcp.md).

**L'onglet Clients n'apparaît que s'il y a un client.** Sans client créé, l'écran n'existe pas dans la barre : ce
n'est pas une fonctionnalité à subir quand on surveille ses propres sites.

---

## Suivre l'usage

La liste des clients affiche, pour chacun, la date de dernière consultation et le nombre de visites. C'est une
information utile dans les deux sens : un client qui ouvre son espace chaque semaine n'a pas besoin qu'on
l'appelle, et un client qui ne l'a jamais ouvert n'a probablement pas compris à quoi sert le lien.

---

## Supprimer un client

La suppression retire le client et détache ses sites. **Les sites, les sondes et tout l'historique sont
conservés.** Un client effacé ne doit pas emmener trente sondes avec lui : ça ne se rattrape pas, alors que
recréer un client prend dix secondes.

---

## Ce que le mode agence ne fait pas

- **Pas de comptes utilisateurs.** Il n'y a qu'un mot de passe, le vôtre. Si plusieurs personnes de l'agence
  doivent administrer l'outil, elles partagent ce mot de passe. C'est un choix, pas un oubli : la gestion des
  utilisateurs coûte trois écrans et un cycle de vie complet pour un besoin que la plupart des agences n'ont pas.
- **Le client ne peut rien déclencher.** Ni relancer une vérification, ni acquitter une alerte, ni demander un
  rapport. La page se consulte, elle ne se pilote pas.
- **Pas de logo client ni de sous-domaine dédié.** L'espace porte le nom que vous donnez à l'installation. Une
  marque blanche complète serait une autre fonctionnalité.

---

## Dépannage

**Le lien affiche « Lien invalide ou expiré ».** Trois causes, une seule réponse volontairement : le jeton est
faux, il a été changé, ou l'accès est fermé. Vérifiez la case *Accès ouvert* et recopiez le lien depuis l'écran
Clients.

**Le lien commence par `https://votre-adresse-uptimer`.** L'adresse de l'installation n'est pas renseignée :
**Réglages → Application et accès → Adresse de cette installation**.

**Le client dit qu'il voit un site qui n'est pas à lui.** Cela n'est pas possible par construction, mais c'est un
ticket à ouvrir immédiatement avec la copie d'écran : ce serait la seule faille qui compte vraiment ici.

**Un site n'apparaît dans aucun espace.** Il n'est rattaché à aucun client. Le bas de l'écran Clients le dit et
en donne le compte.

---

[← Documentation](README.md) · [Rapports](rapports.md) · [Serveur MCP](mcp.md) · [Veille de sécurité](veille-securite.md)
