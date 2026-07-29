# UptimeEZ : analyse concurrentielle et backlog

Document de travail. Il sert de brief de conception : chaque décision d'interface renvoie à un
constat vérifié sur les produits concurrents.

---

## 1. Ce que disent les utilisateurs des concurrents

| Produit | Points forts reconnus | Reproches récurrents |
|---|---|---|
| **UptimeRobot** | mise en route immédiate, offre gratuite généreuse, notoriété | « Consistently bad UI », « Confusing UI » ; faux positifs à l'échelle ; facturation par contact d'alerte ; personnalisation d'alerte pauvre ; pas de contrôle scripté |
| **Uptime Kuma** | interface réactive et agréable, intervalles à 20 s, sondes *push*, page d'état, auto-hébergé | tout se configure sonde par sonde, à la main ; aucune détection automatique ; ingérable au-delà de ~100 sondes ; pas de notion de « site » |
| **Site24x7** | couverture fonctionnelle très large | « interface encombrée de configurations, lourde en clics, à l'étroit » ; écrasante pour un nouvel arrivant ; gestion des alertes frustrante quand le parc grandit |
| **Checkly** | monitoring-as-code, Playwright natif, excellent pour une équipe dev | « l'interface peut être franchement confuse » ; périmètre volontairement étroit ; suppose de savoir coder |
| **Zabbix** | puissance, gratuité, extensibilité | « bloqué dans le passé » ; courbe d'apprentissage raide ; hôtes/items/triggers/templates à monter un par un ; réglage fin des alertes réservé aux experts |
| **New Relic** | profondeur d'analyse | fatigue d'alerte reconnue par l'éditeur lui-même, qui vend des « decisions » pour corréler le bruit |
| **SiteGuru** *(SEO, pas uptime)* | **transforme des données d'audit en liste de tâches priorisées** ; « SEO audits that actually tell you what to fix » ; interface épurée | périmètre SEO uniquement |

### Trois constats qui structurent tout le reste

1. **Le coût de configuration est le vrai frein.** Kuma, Zabbix et Site24x7 demandent de tout déclarer
   à la main. Personne ne propose de *deviner* les réglages à partir du site.
2. **Le bruit d'alerte est le second frein.** UptimeRobot est critiqué pour ses faux positifs, New Relic
   vend une couche de corrélation. Une alerte par site quand un serveur tombe est un anti-patron.
3. **Les données ne valent rien sans la conduite à tenir.** Le seul produit unanimement salué pour son
   UX de la liste ci-dessus est SiteGuru, précisément parce qu'il répond « voilà quoi corriger, dans cet
   ordre ». Aucun outil d'uptime ne fait ça.

### Positionnement retenu

> Les autres montrent **des états**. UptimeEZ donne **une liste de choses à faire**, et devine tout le reste.

Trois règles de conception, opposables à chaque écran :

- **Zéro réglage pour démarrer.** Ce qui peut être déduit du site est déduit, et l'outil explique ce
  qu'il a décidé plutôt que de le demander.
- **Un écran, une lecture de haut en bas, on s'arrête quand c'est vert.** Aucun tableau de bord à
  interpréter : une file de priorités.
- **Chaque problème porte son action.** L'action se fait sur place, jamais dans un écran de réglages.

---

## 2. Backlog

Légende : **✅ livré** · **◐ partiel** · **▶︎ prêt** (spécifié, à développer) · **◻︎ à cadrer**.

> Recalé le 2026-07-29, fichier par fichier : six items annoncés « prêts » étaient en
> réalité livrés depuis plusieurs itérations. Un backlog en retard sur le code envoie
> retravailler du fait, ce qui coûte plus cher qu'un backlog vide.

### Épopée A : Ne rien avoir à configurer

**A1 ✅ En tant que gérant d'agence, je colle une liste de domaines et je n'ai rien d'autre à faire.**
- Étant donné un texte quelconque (domaines, URLs, lignes `client | domaine`, adresses noyées dans de la prose)
- Quand je le colle et que je valide
- Alors UptimeEZ extrait les candidats, écarte les doublons, détecte la technologie, choisit les pages
  représentatives, déduit la chaîne de preuve et crée les sondes : sans autre saisie.

**A2 ✅ En tant qu'utilisateur, je vois ce qui va être créé avant de valider.**
- Un aperçu liste chaque site retenu, la cadence proposée, les pages qui seront suivies, les lignes rejetées.
- Critère d'acceptation : aucune création n'a lieu avant confirmation ; l'aperçu tient dans un écran.

**A3 ✅ En tant qu'utilisateur, les seuils s'ajustent d'eux-mêmes à chaque site.**
- Le seuil de lenteur est calculé sur les premières mesures (p95 × 1,8, borné), pas fixé arbitrairement.
- La cadence dépend de l'importance de la page : accueil plus souvent que mentions légales.
- Critère : après 20 mesures, le seuil est recalculé automatiquement et l'ancien est journalisé.

**A4 ✅ En tant qu'utilisateur, je peux savoir *pourquoi* UptimeEZ a choisi ces réglages.**
- Chaque sonde affiche en clair les décisions prises et leur justification.
- Critère : une ligne par décision, en français, sans jargon.

**A5 ✅ En tant qu'agence, j'importe depuis un sitemap ou un fichier CSV d'hébergeur.**
- Coller l'URL d'un `sitemap.xml` suffit à proposer les pages ; un CSV à colonnes est reconnu (domaine, nom, groupe).

**A6 ◻︎ En tant qu'agence, je connecte l'API de mon hébergeur (o2switch/cPanel) pour importer les domaines.**

### Épopée B : Aller droit à ce qu'il faut faire

**B1 ✅ En tant qu'utilisateur, l'écran d'accueil est une liste de tâches, pas un tableau de bord.**
- Trois blocs : *À traiter maintenant*, *À prévoir*, *Tout va bien* (replié, une ligne).
- Critère : quand tout va bien, la page tient en moins d'un écran et affiche une phrase verte.

**B2 ✅ En tant qu'utilisateur, chaque problème me dit quoi faire et me le fait faire sur place.**
- Cause, impact, conduite à tenir, puis les actions : revérifier, ouvrir, copier le rapport,
  réapprendre la référence, mettre en pause, ouvrir la fiche.
- Critère : aucune de ces actions ne quitte la page ; chacune donne un retour visuel immédiat.

**B3 ✅ En tant qu'utilisateur, je suis prévenu de ce qui *va* casser.**
- Certificat sous 30 jours, domaine sous 45 jours, ralentissement durable, dérive du CSS, `noindex`.
- Critère : un ralentissement de +50 % sur 3 jours apparaît dans « À prévoir » avant toute panne.

**B4 ✅ En tant qu'utilisateur, tout est atteignable au clavier sans chercher dans un menu.**
- Palette de commandes (`Ctrl/⌘ K`) : aller à un site, lancer une action, ajouter, changer de vue.
- Critère : ajouter un site depuis n'importe quel écran en 2 frappes.

**B5 ✅ En tant qu'utilisateur, une action de trop se répare.**
- Toute action destructive ou perturbante propose « Annuler » pendant 8 secondes.

**B6 ✅ En tant qu'utilisateur, je regroupe les problèmes par cause probable.**
- « Ces 6 sites partagent le serveur 51.x.x.x » remonte comme un seul élément à traiter.

### Épopée C : Ne pas se faire noyer par les alertes

**C1 ✅ En tant qu'astreinte, une panne de serveur me vaut une alerte, pas quarante.**
- Corrélation par IP contactée, seuil à 3 sites distincts, message qui nomme le serveur.

**C2 ✅ En tant qu'utilisateur, je peux couper le bruit sans couper la surveillance.**
- Heures calmes (les pannes réelles passent), fenêtres de maintenance, « pris en compte ».

**C3 ✅ En tant qu'utilisateur, une alerte récurrente me propose de s'auto-régler.**
- Après 3 alertes de lenteur en 7 jours sur la même sonde, UptimeEZ propose de relever le seuil (un clic).

**C4 ▶︎ En tant qu'astreinte, je reçois un résumé quotidien au lieu d'alertes unitaires pour le non-urgent.**
- Digest de 8 h : ce qui a été détecté, ce qui est réparé, ce qui arrive.

### Épopée D : Surveiller ce que les autres ne voient pas

**D1 ✅ Mise en page cassée** : 9 signaux croisés, messages console reconstitués. *(Aucun concurrent grand public ne le fait.)*
**D2 ✅ Base de données HS derrière un 200** : signatures d'erreur + chaîne de preuve + sonde CMS.
**D3 ✅ `noindex` oublié en production** : spécificité agence, absent partout ailleurs.
**D4 ✅ Sonde *dead-man* (battement)**, surveiller le cron ou la sauvegarde d'un client : c'est l'absence
de signal qui déclenche l'alerte. Équivalent des *push monitors* de Kuma, avec URL prête à copier.
**D5 ✅ Cœur des Web Vitals**. LCP/CLS/INP via l'API PageSpeed, une mesure par jour, en tendance.
**D6 ✅ Veille de vulnérabilités WordPress** : version du cœur et des plugins visibles, croisées avec un flux CVE.
**D7 ◻︎ Parcours scripté sans navigateur** : enchaîner 3 requêtes (accueil → formulaire → confirmation)
avec extraction de jeton, pour valider un tunnel de contact sans Playwright.

### Épopée E : Rendre des comptes au client

**E1 ✅ Rapport client imprimable** : un écran par site, période au choix, prêt à envoyer en PDF.
**E2 ✅ Export CSV des incidents** : justificatif de SLA.
**E3 ✅ Page d'état publique par jeton** : à partager sans donner d'accès.
**E4 ✅ Envoi automatique du rapport mensuel** par e-mail au client.

### Épopée F : Tenir à l'échelle

**F1 ✅ 300 sondes sur un mutualisé** : agrégation SQL, plafond d'analyses coûteuses par passe, purge.
**F2 ✅ Mise à jour sans intervention** : le schéma se complète tout seul.
**F3 ◐ Vue « mur »** pour écran d'agence, sans authentification, jeton dédié.
  *Partiel : la page de statut publique par jeton existe (`p=status&token=`) ; le mur
  plein écran sans authentification, pour un téléviseur d'agence, reste à faire.*
**F5 ✅ Renommage en UptimeEZ** : nom, dossier, base, catalogues, documentation.
**F4 ✅ Multi-utilisateur et accès client en lecture seule.**
  *Mode agence livré : `src/Client.php`, un jeton par client, cloisonnement vérifié
  par le banc d'essai. Le multi-utilisateur au sens comptes nominatifs relève de la
  coque SaaS (cdwstarterkit), pas du moteur.*

### Épopée G : Internationalisation

**G1 ✅ i18n, 10 langues, anglais par défaut.** Moteur `I18n` : les clés de traduction sont les phrases
françaises du source (msgid à la gettext), donc aucune clé technique dans les gabarits et une chaîne
oubliée reste lisible. Repli en cascade langue → anglais → source. Négociation sans jamais poser la
question : `?lang=` → choix mémorisé → réglage d'instance → `Accept-Language` → anglais. Règles de
pluriel par famille (trois formes pour le russe et l'arabe). Écriture de droite à gauche pour l'arabe et
l'ourdou, avec les mesures, URL et extraits de code qui restent en lecture gauche-droite. Nombres
formatés selon la langue. `bin/i18n-audit.php` mesure la couverture, liste les fragments intraduisibles
et les littéraux encore hors traduction.
**G2 ✅ Catalogues.** Anglais 796/796 et français (langue source) complets. Huit autres langues couvrent
l'interface d'exploitation ; les textes d'aide longs retombent sur l'anglais.

### Épopée H : Réduire la charge cognitive

**H1 ✅ Interrupteur Simple / Complet.** Un clic dans la barre change tout : la navigation, le contenu
des cartes, les blocs de la fiche de sonde, l'étendue du formulaire. Simple est le défaut : pari
assumé. Un champ masqué reste envoyé avec sa valeur : changer de mode ne peut pas désactiver une sonde.
**H2 ✅ Aides contextuelles.** Un `?` sur les dix-sept notions qui font hésiter (chaîne de preuve, seuil
de lenteur, chute CSS tolérée, relances, heures calmes, jeton public…). Accessibles au clavier
(`aria-describedby`, `role="tooltip"`), positionnées en JavaScript pour échapper à tout parent qui les
rognerait, et jamais contaminées par la casse d'un titre de section.
**H3 ✅ Béta-test destructif.** `bin/chaos.php` : 825 requêtes hostiles jouant un utilisateur qui écrit
mal, clique partout et cherche à casser. Contrat vérifié : aucun 500, aucun message PHP dans la page,
aucune saisie réinjectée, base cohérente à l'arrivée. Deux vrais bugs trouvés.
**H4** → fusionné avec **C4** (même besoin décrit deux fois, dans deux épopées).

---

## 3. Sources

- [UptimeRobot Reviews (2026): What Users Actually Say. Hyperping](https://hyperping.com/blog/uptimerobot-reviews)
- [Best Uptime Kuma Alternatives. Hyperping](https://hyperping.com/blog/best-uptime-kuma-alternatives)
- [Uptime Kuma vs UptimeRobot. StackShare](https://stackshare.io/stackups/uptime-kuma-vs-uptimerobot)
- [Site24x7 Reviews & Ratings. Gartner Peer Insights](https://www.gartner.com/reviews/product/manageengine-site24x7)
- [Site24x7 Reviews. Capterra](https://www.capterra.com/p/168192/Site24x7/reviews/)
- [Site24x7 Reviews. GetApp](https://www.getapp.com/it-management-software/a/site-24x7/reviews/)
- [Checkly Pros and Cons. G2](https://www.g2.com/products/checkly/reviews?qs=pros-and-cons)
- [Checkly Review: Monitoring as Code. Modern DataTools](https://www.modern-datatools.com/tools/checkly)
- [Zabbix Pros and Cons. G2](https://www.g2.com/products/zabbix/reviews?qs=pros-and-cons)
- [Zabbix Review 2026. The CTO Club](https://thectoclub.com/tools/zabbix-review/)
- [5 Common Sources of Alert Fatigue. New Relic](https://newrelic.com/blog/observability/alert-fatigue-sources)
- [SiteGuru Review: SEO Audits That Actually Tell You What to Fix. Revuary](https://revuary.com/reviews/siteguru-review/)
- [SiteGuru Reviews. G2](https://www.g2.com/products/siteguru-siteguru/reviews)
