<?php
/**
 * Uptimeez : le mode démonstration publique, et ses verrous.
 *
 * Une démo publique d'un outil de supervision n'est pas une vitrine anodine :
 * **c'est un relais ouvert**. Uptimeez va chercher les URL qu'on lui donne, c'est
 * son métier. Sur une instance privée c'est normal ; sur une démo où n'importe
 * qui peut ajouter une sonde, ça permet de faire balayer les ports internes de la
 * machine qui l'héberge, de refléter du trafic vers un tiers depuis notre adresse,
 * et de transformer un webhook d'alerte en canal d'exfiltration.
 *
 * Deux principes gouvernent cette classe.
 *
 * 1. **Le drapeau vient de l'environnement, pas de la configuration.** Un
 *    visiteur atteint l'écran des réglages : tout ce qui n'est verrouillé que par
 *    un réglage est contournable en trois clics. `UPTIMEEZ_DEMO=1` ne s'écrit
 *    que dans la configuration du serveur web, hors de portée de l'interface.
 *
 * 2. **On verrouille dans le code de celui qui agit**, pas dans son appelant.
 *    Chaque expéditeur de notification refuse d'émettre lui-même, plutôt que de
 *    faire confiance à un aiguillage en amont. Une garde qu'on peut contourner en
 *    appelant la fonction d'à côté n'est pas une garde.
 *
 * Ce que la démo laisse faire, volontairement : tout ce qui se regarde, et les
 * actions qui montrent le produit sans rien exposer (vérifier une sonde
 * maintenant, mettre en pause, accuser réception d'un incident, changer de
 * langue, basculer le niveau de détail). Une démo qui ne laisse rien faire ne
 * démontre rien.
 */
declare(strict_types=1);

namespace Uptimeez;

final class Demo
{
    /**
     * Actions de formulaire refusées en démonstration.
     *
     * Le classement suit une question : est-ce que ça expose quelque chose, ou
     * est-ce que ça casse la démo pour le visiteur suivant ?
     */
    private const REFUSED = [
        // Ajouter une sonde, c'est faire chercher une URL choisie par le
        // visiteur : c'est LE vecteur d'abus, celui qui compte vraiment.
        'preview', 'import', 'save_monitor',
        // Les réglages portent le mot de passe, la clé de cron, le jeton public
        // et les canaux d'alerte. Le premier visiteur fermerait la démo derrière
        // lui, ou la ferait émettre où il veut.
        'save_settings',
        // Tout ce qui émet vers l'extérieur, ou qui enregistre une adresse à qui
        // émettre plus tard.
        'test_notify', 'send_site_report', 'save_autoreport', 'save_site_report',
        // Destructif : réversible pour nous à la remise à zéro, mais la démo
        // resterait vide jusqu'à l'heure suivante.
        'delete_monitor',
        // Les accès client créent et font tourner des jetons publics.
        'client_create', 'client_save', 'client_rotate', 'client_delete', 'client_from_groups',
        // Purge et consolidation : sans intérêt à montrer, et coûteux si on
        // l'appelle en boucle.
        'maintenance_cron',
    ];

    /** Opérations de masse refusées, même quand « bulk » est autorisé. */
    private const REFUSED_BULK = ['delete'];

    private static ?bool $on = null;

    /**
     * Sommes-nous en démonstration publique ?
     *
     * Lu dans l'environnement une seule fois. Volontairement insensible à la
     * configuration : voir le principe 1 en tête de fichier.
     */
    public static function on(): bool
    {
        if (self::$on === null) {
            $v = (string)(getenv('UPTIMEEZ_DEMO') ?: '');
            self::$on = $v !== '' && $v !== '0' && strtolower($v) !== 'false';
        }
        return self::$on;
    }

    /**
     * Applique ce qui doit l'être avant toute requête. Appelé par bootstrap.php.
     *
     * Le blocage des plages privées est **autorisé par défaut** dans le produit,
     * parce que surveiller un intranet est un usage légitime et fréquent. Sur une
     * démo publique il devient obligatoire : c'est ce qui empêche de faire
     * balayer 127.0.0.1 et le réseau local de la machine d'hébergement.
     */
    public static function apply(): void
    {
        if (!self::on()) return;
        Config::set('security.block_private_ranges', true);
        Config::set('app.demo', true);
        // Aucun canal ne doit émettre. La configuration est la première barrière,
        // les expéditeurs eux-mêmes sont la seconde (voir Demo::silenced()).
        foreach (['discord', 'slack', 'mail', 'webhook'] as $ch) {
            Config::set('notify.' . $ch . '.enabled', false);
        }
    }

    /**
     * Un expéditeur doit-il se taire ? À appeler au tout début de chaque send().
     *
     * @return array{ok:bool,info:string}|null null = rien à faire, poursuivre
     */
    public static function silenced(): ?array
    {
        if (!self::on()) return null;
        return ['ok' => false, 'info' => t('Aucun envoi depuis la démonstration.')];
    }

    /** Cette action de formulaire est-elle refusée ? */
    public static function refuses(string $action, ?string $bulkOp = null): bool
    {
        if (!self::on()) return false;
        if (in_array($action, self::REFUSED, true)) return true;
        return $action === 'bulk' && $bulkOp !== null
               && in_array($bulkOp, self::REFUSED_BULK, true);
    }

    /** Le message rendu à l'écran quand une action est refusée. */
    public static function refusal(): array
    {
        return ['warn', t('Action désactivée dans la démonstration : elle est remise à zéro chaque heure, et rien n\'en sort. Installez {app} pour tout essayer.')];
    }
}
