# Piloter Uptimeez depuis un agent (MCP)

[← Exploitation](exploitation.md) · [Documentation](README.md)

Uptimeez embarque un serveur MCP : Claude Code, Claude Desktop ou n'importe quel client MCP peut donc
l'interroger sur votre parc et agir sur ses réponses. Il est écrit en PHP comme le reste du projet, sans aucune
dépendance à installer.

---

## Le brancher

Ajoutez le serveur à la configuration de votre client MCP :

```json
{
  "mcpServers": {
    "uptimeez": {
      "command": "php",
      "args": ["/chemin/vers/uptimeez/bin/mcp.php"],
      "env": { "UPTIMEEZ_CONFIG": "/chemin/vers/uptimeez/config.php" }
    }
  }
}
```

`UPTIMEEZ_CONFIG` n'est nécessaire que si votre `config.php` n'est pas à la racine du projet. Pour autoriser
l'agent à agir et pas seulement à lire, ajoutez `--write` dans `args`.

Vérifiez que ça répond avant de le brancher :

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | php bin/mcp.php
```

Vous devez obtenir une poignée de main nommant le serveur, puis le catalogue d'outils.

---

## Ce qu'on peut demander

Les outils sont conçus autour de questions, pas autour de tables de base de données.

> *« Qu'est-ce qui est cassé sur le parc client ce matin ? »*

Appelle `tasks`. Renvoie la liste de tâches, les plus urgentes d'abord, avec la cause en clair, pourquoi c'est un
problème, quoi faire, le relevé technique brut, et la liste des correctifs disponibles pour ce problème précis.
Puis ce qui va casser : certificats qui expirent, domaines à renouveler, sites qui ont ralenti de plus de 50 %.

> *« Est-ce que tout va bien ? »*

Appelle `status`. Une réponse : combien de sites hors service, dégradés, en ligne ou en pause, l'uptime et le
temps de réponse moyens sur 24 heures, et si le collecteur a réellement tourné.

> *« Pourquoi la bêta Deezer ralentit ? Montre-moi la tendance sur 30 jours. »*

Appelle `monitor_detail` puis `response_time_series`. Le détail comprend les temps décomposés par DNS, TLS et
premier octet, le p95, le certificat, et l'audit des ressources qui attrape une mise en page cassée derrière un
HTTP 200.

> *« Combien d'indisponibilité ce client a-t-il eu le mois dernier ? »*

Appelle `incidents` sur une période. Renvoie chaque interruption avec sa cause et sa durée, plus
l'indisponibilité cumulée, qui est le chiffre dont une discussion de SLA a besoin.

> *« Écris-moi quelque chose à envoyer au client. »*

Appelle `report`. Texte brut, diagnostic, remède, chronologie, chiffres de disponibilité, prêt à coller dans un
e-mail.

---

## Les outils

Onze outils en lecture seule, exposés par défaut :

| Outil | Rôle |
|---|---|
| `status` | L'état du parc en un appel |
| `tasks` | La liste de tâches, plus ce qui va casser |
| `list_monitors` | Chercher et filtrer les sondes. Insensible aux accents dans toutes les langues |
| `monitor_detail` | Une sonde en profondeur, ressources et décisions automatiques comprises |
| `incidents` | L'historique des interruptions d'une période, avec l'indisponibilité cumulée |
| `report` | Le rapport prêt à envoyer pour une sonde |
| `response_time_series` | La série temporelle, pour distinguer un pic d'une tendance |
| `web_vitals` | La vitesse ressentie : mesures de terrain d'un côté, causes lues dans la page de l'autre, jamais mélangées |
| `security_advisories` | L'inventaire logiciel du parc et les avis publiés qui le concernent, le plus grave d'abord |
| `list_clients` | Les clients, leurs sites, leur état, et s'ils consultent encore leur espace. Le lien de l'espace n'est jamais renvoyé : il ouvre une page sans authentification, il n'a rien à faire dans une conversation |
| `security_target_check` | Si une adresse serait refusée avant toute requête |

Quatre outils d'écriture, seulement avec `--write` :

| Outil | Rôle |
|---|---|
| `check_now` | Lance une vraie vérification tout de suite, sur une sonde ou sur toutes celles qui sont dues |
| `apply_fix` | Applique un des remèdes listés par `tasks` : réapprendre la référence CSS, recaler le seuil de lenteur, ne plus surveiller le noindex, adopter la cible de redirection, mettre en pause une heure, prendre en compte |
| `set_enabled` | Met une sonde en pause ou la réactive |
| `add_sites` | Ajoute des sites depuis une liste collée. En `dry_run` par défaut, ce qui montre ce qui serait créé |

---

## Pourquoi la lecture seule par défaut

Un agent qui explore ne doit pas pouvoir mettre une sonde en pause par accident, et un agent qui comprend mal une
question ne doit pas pouvoir créer quarante sondes. Les outils qui modifient ne sont donc simplement pas dans le
catalogue si le serveur n'est pas lancé avec `--write`, et même dans ce cas `add_sites` fonctionne en aperçu par
défaut.

Si vous activez l'écriture, le serveur l'annonce dans les instructions qu'il envoie à la poignée de main : l'agent
sait alors qu'il doit vous montrer un aperçu avant de créer quoi que ce soit.

## Ce qu'il n'expose pas

Aucun outil ne lit la configuration, ne change un réglage, ne supprime une sonde, ni ne lit l'empreinte du mot de
passe. La surface MCP est volontairement plus étroite que l'interface web : elle sert à répondre à des questions
et à appliquer les correctifs que l'outil a lui-même proposés.
