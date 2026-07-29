<?php
declare(strict_types=1);

namespace Uptimeez;

/**
 * Mode agence : des clients, et un accès en lecture seule par client.
 *
 * Le problème que ça règle. Une agence surveille trente sites qui appartiennent
 * à douze personnes différentes. Chacune veut savoir si *son* site va bien, et
 * aucune n'a à voir les vingt-neuf autres. Les outils du marché répondent à ça
 * par des comptes utilisateurs, des rôles et des permissions : trois écrans de
 * configuration pour un besoin qui tient en une phrase.
 *
 * Ici, un client, c'est un nom et un lien. Le lien ouvre un espace qui montre
 * ses sites et rien d'autre. Pas de compte à créer, pas de mot de passe à
 * transmettre, pas de mot de passe oublié à réinitialiser un dimanche.
 *
 * Ce qui rend la chose défendable :
 *
 *   - **Cloisonnement par requête, pas par affichage.** Chaque lecture de
 *     l'espace client passe par une clause `client_id = ?`. Il n'existe aucun
 *     chemin où un identifiant fourni par le visiteur choisit ce qu'il voit :
 *     c'est le jeton qui décide, et lui seul.
 *   - **Lecture seule par construction.** L'espace client est une page rendue
 *     sans session d'administration. Les actions d'écriture vivent toutes
 *     derrière `Auth`, donc elles ne sont pas atteignables depuis un jeton.
 *   - **Révocable.** Changer le jeton coupe l'ancien lien immédiatement, sans
 *     rien perdre de l'historique. Désactiver le client ferme l'espace en
 *     gardant le lien pour plus tard.
 *   - **Jeton hors des journaux.** Il voyage dans l'URL, comme pour la page
 *     d'état publique : on l'accepte, mais l'espace envoie `noindex` et
 *     `Referrer-Policy` pour qu'il ne fuite ni dans un moteur ni dans le
 *     référent d'un lien sortant.
 */
final class Client
{
    /** 32 caractères hexadécimaux : imprévisible, et copiable à la main. */
    private const TOKEN_BYTES = 16;

    // =====================================================================
    // Cycle de vie
    // =====================================================================
    public static function create(string $name, string $contact = '', string $notes = ''): int
    {
        $name = str_cut(trim($name), 190);
        if ($name === '') $name = t('Client sans nom');
        return Db::insert('clients', [
            'name'          => $name,
            'token'         => self::newToken(),
            'contact_email' => trim($contact) !== '' ? str_cut(trim($contact), 255) : null,
            'notes'         => trim($notes) !== '' ? str_cut(trim($notes), 2000) : null,
            'enabled'       => 1,
            'created_at'    => now(),
        ]);
    }

    /**
     * Nouveau jeton, garanti absent de la table.
     *
     * La boucle est là par principe : sur 16 octets aléatoires la collision
     * n'arrivera pas, mais un index UNIQUE qui explose en production est une
     * façon idiote de perdre la confiance d'un utilisateur.
     */
    public static function newToken(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $tok = bin2hex(random_bytes(self::TOKEN_BYTES));
            if (!Db::one('SELECT id FROM clients WHERE token = ?', [$tok])) return $tok;
        }
        return bin2hex(random_bytes(self::TOKEN_BYTES + 4));
    }

    /** Change le jeton : l'ancien lien ne fonctionne plus, l'historique reste. */
    public static function rotate(int $id): string
    {
        $tok = self::newToken();
        Db::update('clients', ['token' => $tok], 'id = :__i', ['__i' => $id]);
        return $tok;
    }

    /**
     * Supprime un client sans toucher à ses sites.
     *
     * Un client effacé ne doit pas emmener trente sondes avec lui : les sites
     * sont simplement détachés. C'est réversible, une suppression de données ne
     * l'est pas.
     */
    public static function delete(int $id): void
    {
        Db::q('UPDATE sites SET client_id = NULL WHERE client_id = ?', [$id]);
        Db::q('DELETE FROM clients WHERE id = ?', [$id]);
    }

    /**
     * Trouve un client par son jeton.
     *
     * La comparaison finale passe par hash_equals : la requête indexée sert à
     * trouver la ligne, et c'est la vérification en temps constant qui décide.
     * Un jeton vide ou mal formé est rejeté avant toute requête.
     */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('~^[0-9a-f]{24,72}$~', $token)) return null;
        $row = Db::one('SELECT * FROM clients WHERE token = ?', [$token]);
        if (!$row) return null;
        if (!hash_equals((string)$row['token'], $token)) return null;
        if ((int)$row['enabled'] !== 1) return null;
        return $row;
    }

    /** Trace de consultation : utile pour savoir si le client regarde vraiment. */
    public static function touch(int $id): void
    {
        Db::q('UPDATE clients SET last_seen_at = ?, views = views + 1 WHERE id = ?', [now(), $id]);
    }

    /** Adresse complète de l'espace, à copier pour le client. */
    public static function url(array $client): string
    {
        $base = rtrim((string)Config::get('app.base_url', ''), '/');
        return ($base !== '' ? $base : 'https://votre-adresse-uptimeez')
             . '/index.php?p=client&k=' . (string)$client['token'];
    }

    // =====================================================================
    // Rattachement des sites
    // =====================================================================
    /**
     * Fixe la liste exacte des sites d'un client.
     *
     * Les identifiants viennent d'un formulaire, donc ils sont filtrés sur ce
     * qui existe réellement avant d'écrire quoi que ce soit.
     */
    public static function setSites(int $clientId, array $siteIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $siteIds), fn($i) => $i > 0)));
        Db::q('UPDATE sites SET client_id = NULL WHERE client_id = ?', [$clientId]);
        if (!$ids) return 0;
        Db::chunk($ids, function (array $part) use ($clientId): array {
            $in = implode(',', array_fill(0, count($part), '?'));
            Db::q("UPDATE sites SET client_id = ? WHERE id IN ($in)", array_merge([$clientId], $part));
            return [];
        });
        return (int)Db::val('SELECT COUNT(*) FROM sites WHERE client_id = ?', [$clientId]);
    }

    /**
     * Crée un client par groupe de sites déjà existant.
     *
     * L'agence qui a importé ses sites avec un groupe (« Mairie de Fréjus »,
     * « Boutiques ») a déjà fait le classement. On le reprend au lieu de le lui
     * redemander. Rien n'est écrasé : un site déjà rattaché reste où il est, et
     * un client du même nom est réutilisé plutôt que dupliqué.
     *
     * @return array{created:int,linked:int}
     */
    public static function fromGroups(): array
    {
        $out = ['created' => 0, 'linked' => 0];
        $groups = Db::all("SELECT DISTINCT group_name FROM sites
                           WHERE group_name IS NOT NULL AND group_name <> '' AND client_id IS NULL");
        foreach ($groups as $g) {
            $name = trim((string)$g['group_name']);
            if ($name === '') continue;
            $existing = Db::one('SELECT id FROM clients WHERE name = ?', [$name]);
            if ($existing) {
                $id = (int)$existing['id'];
            } else {
                $id = self::create($name);
                $out['created']++;
            }
            $out['linked'] += Db::q('UPDATE sites SET client_id = ? WHERE client_id IS NULL AND group_name = ?',
                                    [$id, $name])->rowCount();
        }
        return $out;
    }

    // =====================================================================
    // Lectures, toutes cloisonnées
    // =====================================================================
    /** Sites d'un client, avec l'état de leur sonde principale. */
    public static function sites(int $clientId): array
    {
        return Db::all("SELECT s.id, s.name, s.domain, s.cms,
                               m.id AS monitor_id, m.status, m.url, m.uptime_30d, m.uptime_24h,
                               m.last_ms, m.last_check_at, m.enabled
                        FROM sites s
                        LEFT JOIN monitors m ON m.site_id = s.id AND m.role = 'primary'
                        WHERE s.client_id = ?
                        ORDER BY s.name ASC", [$clientId]);
    }

    /**
     * État d'ensemble d'un client.
     *
     * Les sondes en pause ne comptent pas comme des pannes : une surveillance
     * suspendue par l'agence n'est pas un incident pour le client.
     *
     * @return array{sites:int,down:int,degraded:int,up:int,uptime:?float,worst:string}
     */
    public static function overview(int $clientId): array
    {
        $rows = self::sites($clientId);
        $out = ['sites' => count($rows), 'down' => 0, 'degraded' => 0, 'up' => 0,
                'uptime' => null, 'worst' => 'unknown'];
        $sum = 0.0; $n = 0;
        foreach ($rows as $r) {
            if ((int)($r['enabled'] ?? 1) !== 1) continue;
            $st = (string)($r['status'] ?? 'unknown');
            if ($st === 'down')          $out['down']++;
            elseif ($st === 'degraded')  $out['degraded']++;
            elseif ($st === 'up')        $out['up']++;
            if ($r['uptime_30d'] !== null) { $sum += (float)$r['uptime_30d']; $n++; }
        }
        if ($n > 0) $out['uptime'] = $sum / $n;
        $out['worst'] = $out['down'] ? 'down' : ($out['degraded'] ? 'degraded'
                      : ($out['up'] ? 'up' : 'unknown'));
        return $out;
    }

    /** Incidents des sites d'un client, du plus récent au plus ancien. */
    public static function incidents(int $clientId, int $limit = 20): array
    {
        return Db::all("SELECT i.*, m.name AS monitor_name, s.name AS site_name
                        FROM incidents i
                        JOIN monitors m ON m.id = i.monitor_id
                        JOIN sites s ON s.id = m.site_id
                        WHERE s.client_id = ?
                        ORDER BY i.started_at DESC
                        LIMIT ?", [$clientId, max(1, min(200, $limit))]);
    }

    /** Identifiants des sondes d'un client : sert aux graphiques de l'espace. */
    public static function monitorIds(int $clientId): array
    {
        $rows = Db::all("SELECT m.id FROM monitors m JOIN sites s ON s.id = m.site_id
                         WHERE s.client_id = ? AND m.enabled = 1", [$clientId]);
        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    // =====================================================================
    // Vue agence
    // =====================================================================
    /** Liste pour l'écran de gestion : un client par ligne, avec son état. */
    public static function all(): array
    {
        $rows = Db::all('SELECT * FROM clients ORDER BY name ASC');
        foreach ($rows as &$r) {
            $r['overview'] = self::overview((int)$r['id']);
        }
        return $rows;
    }

    /** Sites sans client : ce qui reste à classer, affiché tel quel. */
    public static function orphanSites(): array
    {
        return Db::all("SELECT id, name, domain, group_name FROM sites
                        WHERE client_id IS NULL ORDER BY name ASC");
    }

    /**
     * Destinataires du rapport mensuel d'un site.
     *
     * Le réglage du site gagne toujours. Sans lui, l'adresse du client sert de
     * repli : c'est ce qui évite de saisir la même adresse sur ses huit sites.
     */
    public static function reportRecipients(array $site): string
    {
        $own = trim((string)($site['report_to'] ?? ''));
        if ($own !== '') return $own;
        if (empty($site['client_id'])) return '';
        $c = Db::one('SELECT contact_email FROM clients WHERE id = ?', [(int)$site['client_id']]);
        return trim((string)($c['contact_email'] ?? ''));
    }
}
