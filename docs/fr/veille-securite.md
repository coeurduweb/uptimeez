# Veille de sécurité

**Uptimer sait déjà quelle version tourne sur chaque site qu'il surveille. Il ne reste plus qu'à demander si
cette version a une faille publiée.**

[← Documentation](README.md) · [English version](../en/security-watch.md)

---

## Ce que ça change

Un outil de surveillance classique vous prévient quand un site tombe. Celui-ci vous prévient **avant**, quand un
avis de sécurité vient d'être publié sur la version que votre client exécute encore.

C'est le même travail de fond que la détection de mise en page cassée : Uptimer récupère déjà le HTML de chaque
page surveillée, donc l'information est là, gratuite, il suffisait de la lire.

![Logiciels et failles connues](../img/vulnerabilities.png)

---

## Deux signaux, jamais confondus

C'est le point qui décide si vous pouvez faire confiance à cet écran.

| Signal | Ce que ça veut dire | Ce que ça vaut |
|---|---|---|
| **Une faille publiée** | Un avis de sécurité identifié couvre **précisément** la version détectée. L'identifiant, la date et le lien sont affichés. | À traiter. Rien n'a été deviné. |
| **Version en retard** | La version installée est antérieure à la dernière publiée. | Une dette, pas une faille. À planifier. |

Confondre les deux serait le plus court chemin pour perdre votre confiance. Un outil qui affiche « vulnérable »
alors qu'il ne sait que « pas à jour » se fait ignorer en trois semaines, et le jour où il a raison, personne ne
regarde. Uptimer emploie donc deux mots différents, deux couleurs différentes, et n'affiche jamais de gravité
qu'il n'a pas lue dans un avis : quand l'avis n'annonce rien, l'écran dit « gravité non annoncée ».

---

## Ce qui est lu, et où

Rien n'est demandé de plus au site surveillé. Les versions viennent de trois endroits déjà présents dans la
réponse HTTP :

| Source | Exemple | Fiabilité |
|---|---|---|
| La balise `generator` | `<meta name="generator" content="WordPress 6.4.2">` | Bonne, mais souvent tronquée à la version majeure |
| Le paramètre de cache des fichiers statiques | `/wp-includes/js/dist/url.min.js?ver=6.4.2` | Excellente, c'est la version réelle du cœur |
| Le chemin des composants | `/wp-content/plugins/contact-form-7/…?ver=5.8.1` | Bonne pour l'extension et sa version |

Quand deux lectures se contredisent, **la plus précise gagne** : « Drupal 10 » lu dans la balise `generator`
cède devant « 10.1.6 » lu sur `drupal.js`, parce que c'est ce numéro-là qui permet de dire si une faille
concerne ce site.

Et quand aucune version n'est lisible, le composant est enregistré **sans version** plutôt qu'avec une
approximation. Il apparaît alors en « non lisible » et aucune interrogation n'est lancée pour lui : il n'y a
rien à comparer, et une fausse alerte de sécurité coûte plus cher qu'une absence d'alerte.

Sont inventoriés : le cœur (WordPress, Drupal, Joomla, PrestaShop, TYPO3, Magento, Laravel, Symfony), les
extensions et les thèmes WordPress, les modules PrestaShop et Drupal. Quarante composants au maximum par site,
pour qu'une page bavarde ne produise pas un inventaire sans fin.

---

## Les sources d'avis

Deux sources publiques, sans compte et sans clé d'API :

- **[OSV.dev](https://osv.dev)** pour tout ce qui se publie sur Packagist : Drupal, Laravel, Symfony, TYPO3,
  Magento, PrestaShop, Joomla. La réponse est déjà filtrée par version, donc un avis affiché couvre bien la
  version détectée.
- **api.wordpress.org** pour la dernière version du cœur WordPress, de ses extensions et de ses thèmes.

### Ce qui sort de chez vous

À dire clairement, parce que c'est le seul flux sortant d'Uptimer qui ne va pas vers un site que vous
surveillez : la requête envoie **le nom du composant et son numéro de version**. Jamais l'adresse du site
concerné, jamais le nom de votre client, jamais l'inventaire complet en un seul appel. Une source d'avis
apprend que quelqu'un s'intéresse à `drupal/core 10.1.6`, rien de plus.

Si cela reste de trop, l'interrogation se coupe dans **Réglages → Veille de sécurité**. L'inventaire des
versions, lui, continue : il est local et ne coûte aucune requête.

### Ce que ça coûte

Une interrogation **par composant et par version**, gardée sept jours. Un parc de cent sites qui partagent une
dizaine d'extensions ne produit donc pas cent requêtes par jour, mais quelques dizaines la première fois puis
presque rien. La passe est en plus plafonnée à vingt-cinq interrogations par entretien quotidien, et le délai
d'attente par source est court : la veille ne retarde jamais une vérification de site.

Quand un site est mis à jour, le verdict repart de zéro. Un site passé de 6.4.2 à 6.7.1 ne reste pas marqué
vulnérable en attendant la prochaine interrogation.

---

## Où ça apparaît

**Sur la fiche d'une sonde**, un bloc « Logiciels et failles connues » liste les composants du site avec leur
version, la dernière version publiée, et le verdict. Rouge pour une faille publiée, orange pour une version en
retard, gris pour ce qui n'a rien à signaler.

**Sur l'écran d'accueil**, une faille publiée devient une tâche, au même titre qu'une panne : ce qui est cassé,
pourquoi c'est un problème, quoi faire. Les avis de gravité haute sont classés avant le reste.

**Depuis un agent**, l'outil MCP `security_advisories` rend le même inventaire en JSON, et
`monitor_detail` porte la liste des composants du site. Voir [Serveur MCP](mcp.md).

---

## Réglages

Dans **Réglages → Veille de sécurité** :

| Réglage | Effet |
|---|---|
| Croiser les versions avec les avis publiés | Coupe l'interrogation des sources. L'inventaire local continue. |
| Délai maximum des interrogations | Temps accordé à une source pour répondre, 8 secondes par défaut. |

En ligne de commande :

```bash
php cron.php --vuln     # force une passe de veille sans attendre 3 h du matin
```

Dans `config.php`, si vous préférez les fichiers aux écrans :

```php
'vuln' => [
    'enabled'     => true,
    'timeout_sec' => 8,
],
```

---

## Ce que la veille ne fait pas

- **Elle ne scanne pas votre site.** Aucune requête n'est envoyée pour tester une faille, aucun chemin
  d'administration n'est sondé, aucune charge n'est injectée. Uptimer lit ce que la page publie et interroge des
  bases publiques. Un outil qui teste réellement les failles est un scanner de vulnérabilités : c'est un autre
  métier, et il se lance avec une autorisation écrite.
- **Elle ne voit pas ce que le HTML ne dit pas.** Une extension qui ne charge ni CSS ni JavaScript sur la page
  d'accueil est invisible. Un site qui retire les paramètres de version de ses fichiers statiques donne son
  inventaire sans les numéros. C'est une limite assumée : mieux vaut un inventaire partiel et exact qu'un
  inventaire complet et deviné.
- **Elle ne remplace pas les mises à jour automatiques.** Elle dit ce qui est en retard et ce qui est
  dangereux. Appliquer le correctif reste un geste humain, sur un site dont vous connaissez les particularités.

---

## Dépannage

**Aucun composant listé sur un site.** La sonde a-t-elle déjà été vérifiée depuis l'ajout de cette
fonctionnalité ? L'inventaire est écrit à la vérification suivante. Forcez-la avec le bouton *Vérifier
maintenant*, ou attendez la passe suivante.

**Des composants listés, mais rien de vérifié.** L'interrogation a lieu pendant l'entretien de 3 h du matin.
`php cron.php --vuln` la force tout de suite. Si la colonne reste vide, l'hébergeur bloque probablement les
appels sortants vers `api.osv.dev` et `api.wordpress.org` : le banc d'essai le dit
(`php bin/bench.php`, section *Veille de sécurité*).

**Une version détectée qui ne correspond pas à la réalité.** Un cache HTML périmé ou un CDN peut servir une
ancienne page. La version affichée est celle que le monde voit, ce qui est en soi une information utile.
Si l'écart persiste après vidage du cache, c'est un bon ticket : indiquez l'URL.

---

[← Documentation](README.md) · [Détection](detection.md) · [Rapports](rapports.md) · [Serveur MCP](mcp.md)
