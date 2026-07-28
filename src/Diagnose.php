<?php
declare(strict_types=1);

namespace Uptimer;

/**
 * Traduit un code d'anomalie en explication utile : ce que ça veut dire,
 * ce qu'il faut vérifier en premier. L'objectif est qu'on sache quoi faire
 * sans avoir à interpréter un message technique.
 */
final class Diagnose
{
    /** @return array{title:string,why:string,fix:string,icon:string} */
    public static function explain(?string $code, array $mon = []): array
    {
        $host = isset($mon['url']) ? host_of((string)$mon['url']) : t('le serveur');

        return match ($code) {
            'DNS' => [
                'title' => t('Le nom de domaine ne se résout plus'),
                'why'   => t('Aucune adresse IP n\'est renvoyée pour {host}. Le site est injoignable pour tout le monde.', ['host' => $host]),
                'fix'   => t('Vérifiez que le domaine n\'est pas expiré, puis les serveurs DNS chez le registrar. Une propagation DNS récente peut aussi être en cause.'),
                'icon'  => 'globe',
            ],
            'CONNECT', 'CONNECT_RESET' => [
                'title' => t('Le serveur refuse la connexion'),
                'why'   => t('Le domaine se résout, mais {host} n\'accepte pas la connexion : service web arrêté, pare-feu, ou saturation.', ['host' => $host]),
                'fix'   => t('Vérifiez l\'état du serveur web chez l\'hébergeur et les éventuels blocages d\'IP (pare-feu, mod_security, protection anti-bot).'),
                'icon'  => 'alert',
            ],
            'TIMEOUT' => [
                'title' => t('Le serveur ne répond pas dans le délai imparti'),
                'why'   => t('La connexion s\'établit mais aucune réponse complète n\'arrive. Typique d\'un serveur saturé, d\'une requête SQL bloquée ou d\'un script qui tourne sans fin.'),
                'fix'   => t('Regardez la charge du serveur et les processus PHP en cours. Si le site est simplement lent, augmentez le délai maximum de la sonde.'),
                'icon'  => 'clock',
            ],
            'SSL_EXPIRED' => [
                'title' => t('Le certificat SSL est expiré'),
                'why'   => t('Tous les navigateurs affichent un avertissement de sécurité plein écran. Le site est de fait inaccessible au public.'),
                'fix'   => t('Renouvelez le certificat (Let\'s Encrypt se renouvelle normalement seul : vérifiez le renouvellement automatique côté hébergeur).'),
                'icon'  => 'lock',
            ],
            'SSL_INVALID', 'SSL_HANDSHAKE' => [
                'title' => t('Le certificat SSL est refusé par les navigateurs'),
                'why'   => t('Certificat auto-signé, chaîne incomplète, autorité inconnue ou domaine non couvert : le visiteur voit un écran d\'avertissement.'),
                'fix'   => t('Réémettez le certificat en incluant tous les domaines concernés (avec et sans www) et vérifiez que la chaîne intermédiaire est bien installée.'),
                'icon'  => 'lock',
            ],
            'SSL_SOON' => [
                'title' => t('Le certificat SSL expire bientôt'),
                'why'   => t('Rien n\'est cassé pour l\'instant, mais le compte à rebours est lancé.'),
                'fix'   => t('Vérifiez que le renouvellement automatique fonctionne. Sinon, renouvelez à la main.'),
                'icon'  => 'lock',
            ],
            'HTTP_5XX' => [
                'title' => t('Le serveur renvoie une erreur'),
                'why'   => t('Le serveur reçoit la demande mais échoue à produire la page : erreur PHP, base injoignable, extension manquante, quota atteint.'),
                'fix'   => t('Consultez le journal d\'erreurs de l\'hébergement. Après une mise à jour, désactivez le dernier plugin ou thème installé.'),
                'icon'  => 'alert',
            ],
            'HTTP_404' => [
                'title' => t('La page a disparu'),
                'why'   => t('L\'adresse surveillée renvoie 404 : page supprimée, slug modifié, ou réécriture d\'URL cassée.'),
                'fix'   => t('Si le changement est volontaire, corrigez l\'URL de la sonde. Sinon, restaurez la page ou mettez en place une redirection 301.'),
                'icon'  => 'file',
            ],
            'HTTP_403' => [
                'title' => t('L\'accès est interdit'),
                'why'   => t('Le serveur bloque la requête. Souvent un pare-feu applicatif ou une protection anti-bot qui n\'aime pas le robot de surveillance.'),
                'fix'   => t('Autorisez l\'IP de {app}, ou donnez à la sonde un User-Agent de navigateur classique dans les réglages avancés.'),
                'icon'  => 'shield',
            ],
            'HTTP_401' => [
                'title' => t('Une authentification est demandée'),
                'why'   => t('La page est protégée par mot de passe HTTP.'),
                'fix'   => t('Renseignez l\'identifiant et le mot de passe HTTP dans les réglages avancés de la sonde.'),
                'icon'  => 'key',
            ],
            'HTTP_429' => [
                'title' => t('Trop de requêtes'),
                'why'   => t('Le serveur limite le débit et refuse temporairement les demandes.'),
                'fix'   => t('Espacez les vérifications de cette sonde, ou faites relever la limite côté hébergeur.'),
                'icon'  => 'clock',
            ],
            'HTTP_3XX' => [
                'title' => t('Redirection inattendue'),
                'why'   => t('L\'URL surveillée renvoie vers une autre adresse alors qu\'on attendait la page elle-même.'),
                'fix'   => t('Mettez à jour l\'URL de la sonde vers la destination finale, ou acceptez le code de redirection dans les codes HTTP attendus.'),
                'icon'  => 'external',
            ],
            'REDIRECT_LOOP' => [
                'title' => t('Boucle de redirection'),
                'why'   => t('Le serveur renvoie en boucle d\'une adresse à l\'autre : le navigateur abandonne.'),
                'fix'   => t('Vérifiez les règles de réécriture (http→https, www) et l\'adresse du site dans les réglages du CMS.'),
                'icon'  => 'refresh',
            ],
            'DB_DOWN' => [
                'title' => t('La base de données ne répond plus'),
                'why'   => t('Le serveur web fonctionne mais la couche données est indisponible : le site affiche une page d\'erreur, souvent avec un code 200 trompeur.'),
                'fix'   => t('Vérifiez le service MySQL et le nombre de connexions simultanées chez l\'hébergeur. Contrôlez aussi les identifiants de connexion dans la configuration du CMS.'),
                'icon'  => 'db',
            ],
            'APP_ERROR' => [
                'title' => t('Erreur applicative PHP'),
                'why'   => t('Une erreur fatale interrompt la génération de la page (mémoire, syntaxe, appel sur null).'),
                'fix'   => t('Regardez le journal d\'erreurs PHP. Après une mise à jour, revenez sur le dernier changement déployé.'),
                'icon'  => 'alert',
            ],
            'CSS_BROKEN' => [
                'title' => t('La mise en page est cassée'),
                'why'   => t('La page répond, mais les ressources qui la mettent en forme ne sont pas exploitables : le visiteur voit une page nue, vide ou déstructurée.'),
                'fix'   => t('Ouvrez « Ressources de la page » ci-dessous : chaque fichier fautif y est listé avec sa cause exacte. Après une refonte volontaire, réapprenez la référence.'),
                'icon'  => 'layers',
            ],
            'CSS_DEGRADED' => [
                'title' => t('Ressources de rendu partiellement dégradées'),
                'why'   => t('Une ressource secondaire ou tierce ne répond pas : l\'affichage peut être imparfait sans être cassé.'),
                'fix'   => t('Consultez le détail des ressources. Si c\'est un service tiers, il n\'y a souvent rien à faire côté site.'),
                'icon'  => 'layers',
            ],
            'STRING_MISSING' => [
                'title' => t('La chaîne de contrôle a disparu de la page'),
                'why'   => t('Le texte qui prouve que le contenu est bien servi n\'est plus là. C\'est le signe d\'une page d\'erreur, d\'un contenu vidé, ou d\'une base qui ne répond plus.'),
                'fix'   => t('Ouvrez la page pour voir ce qui s\'affiche réellement. Si le texte a changé de façon volontaire, mettez à jour la chaîne de contrôle.'),
                'icon'  => 'eye',
            ],
            'STRING_FORBIDDEN' => [
                'title' => t('Une chaîne interdite est apparue'),
                'why'   => t('Le texte que vous surveilliez comme signal d\'alerte est présent dans la page.'),
                'fix'   => t('Ouvrez la page : il s\'agit souvent d\'un message de maintenance ou d\'erreur laissé en ligne.'),
                'icon'  => 'alert',
            ],
            'JSON_INVALID', 'JSON_PATH', 'JSON_VALUE' => [
                'title' => t('L\'API ne renvoie pas la réponse attendue'),
                'why'   => t('La requête aboutit mais le contenu JSON ne correspond pas au contrat surveillé.'),
                'fix'   => t('Vérifiez le champ et la valeur attendus dans les réglages de la sonde, puis testez l\'URL à la main.'),
                'icon'  => 'layers',
            ],
            'NOINDEX' => [
                'title' => t('La page est en noindex'),
                'why'   => t('Les moteurs de recherche ont pour consigne de ne pas indexer cette page. En production, c\'est presque toujours un oubli après une mise en ligne.'),
                'fix'   => t('Décochez l\'option de blocage d\'indexation dans le CMS (WordPress : Réglages → Lecture), ou retirez la balise meta robots.'),
                'icon'  => 'eye',
            ],
            'SLOW' => [
                'title' => t('Le temps de réponse dépasse votre seuil'),
                'why'   => t('Le site répond correctement mais lentement : mauvaise expérience et pénalité SEO.'),
                'fix'   => t('Regardez la courbe pour voir si c\'est ponctuel ou installé. Cache serveur, requêtes SQL lourdes et images non optimisées sont les causes habituelles.'),
                'icon'  => 'clock',
            ],
            'HEARTBEAT_LATE' => [
                'title' => t('Le signal attendu n\'est pas arrivé'),
                'why'   => t('Cette sonde ne teste pas une page : elle attend qu\'un script du site se signale. Le silence veut dire que le cron, la sauvegarde ou l\'import surveillé ne s\'est pas exécuté.'),
                'fix'   => t('Vérifiez que la tâche tourne encore côté hébergement, et que la ligne d\'appel à {app} est toujours présente à la fin du script.'),
                'icon'  => 'clock',
            ],
            default => [
                'title' => t('Anomalie détectée'),
                'why'   => t('Le détail de la dernière vérification est indiqué ci-dessous.'),
                'fix'   => t('Lancez une vérification manuelle pour confirmer, puis ouvrez la page dans un navigateur.'),
                'icon'  => 'info',
            ],
        };
    }
}
