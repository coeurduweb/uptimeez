<?php

namespace Uptimeez;

/**
 * Les comptes d'une instance, et la trace de qui entre.
 *
 * ------------------------------------------------------------------------------
 * UN SECRET PARTAGÉ N'EST PAS UN ACCÈS
 * ------------------------------------------------------------------------------
 *
 * Une instance n'avait qu'un mot de passe, tiré au hasard à la création et affiché une
 * seule fois. Dès qu'un client a deux personnes, ce secret circule par courriel ou par
 * message, et il n'existe alors aucun moyen de savoir qui est entré, ni de retirer l'accès
 * à une seule personne. Le seul geste possible est de changer le secret pour tout le monde,
 * ce que personne ne fait, ce qui revient à ne jamais retirer un accès.
 *
 * ------------------------------------------------------------------------------
 * L'ARBITRAGE : LE MOT DE PASSE D'INSTANCE SURVIT, ET IL EST NOMMÉ
 * ------------------------------------------------------------------------------
 *
 * Il aurait été plus propre de le supprimer une fois le premier compte créé. C'est
 * pourtant le contraire qu'il faut faire, et pour une raison d'exploitation : une instance
 * dont la coque est indisponible, ou dont le serveur de courrier est en panne, deviendrait
 * inaccessible AU MOMENT PRÉCIS où on en a besoin. La réinitialisation par courriel ne
 * secourt personne quand c'est le courriel qui est tombé.
 *
 * Il survit donc, mais il ne reste pas anonyme : une session ouverte par lui est marquée
 * « secours » au journal, sous le nom d'exploitant. Un accès de secours dont on ne sait pas
 * qu'il a servi n'est pas un secours, c'est une porte dérobée.
 *
 * ------------------------------------------------------------------------------
 * LE JETON DE RÉINITIALISATION EST STOCKÉ HACHÉ
 * ------------------------------------------------------------------------------
 *
 * Tant qu'il n'a pas expiré, un jeton de réinitialisation vaut un mot de passe. Le garder
 * en clair offrirait tous les comptes à qui lit la base — une sauvegarde égarée, un accès
 * en lecture accordé pour un dépannage. On stocke son empreinte, on envoie le jeton, et on
 * ne peut plus le relire : c'est exactement le traitement d'un mot de passe.
 */
final class Compte
{
    /** Durée de validité d'un lien de réinitialisation : assez pour lire son courrier. */
    public const REINIT_TTL = 3600;

    /** Longueur minimale d'un mot de passe, la même que celle de l'installateur. */
    public const MDP_MIN = 10;

    /**
     * Crée un compte.
     *
     * @throws \InvalidArgumentException si l'identifiant ou le mot de passe est refusé
     */
    public static function creer(
        string $identifiant,
        string $motDePasse,
        string $courriel = '',
        string $nom = '',
    ): int {
        // Comme dans src/Retour.php et src/Exceptions.php, ces messages sont écrits pour
        // qui lit le code : ils nomment la valeur fautive et restent en français. Ils ne
        // sortent pas — l'appelant les met au journal et rend une phrase traduite.
        $brut = $identifiant;
        $identifiant = self::normaliser($identifiant);

        if ($identifiant === '') {
            throw new \InvalidArgumentException("Identifiant vide refusé, reçu « $brut »");
        }

        if (mb_strlen($motDePasse) < self::MDP_MIN) {
            $longueur = mb_strlen($motDePasse);
            $minimum = self::MDP_MIN;
            throw new \InvalidArgumentException(
                "Mot de passe de $longueur caractères refusé, minimum $minimum");
        }

        if (self::parIdentifiant($identifiant) !== null) {
            throw new \InvalidArgumentException("Identifiant déjà pris : « $identifiant »");
        }

        return Db::insert('comptes', [
            'identifiant'  => $identifiant,
            'courriel'     => trim($courriel) !== '' ? trim($courriel) : null,
            'nom'          => trim($nom) !== '' ? str_cut(trim($nom), 190) : null,
            'mot_de_passe' => password_hash($motDePasse, PASSWORD_DEFAULT),
            'actif'        => 1,
            'cree_le'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * L'identifiant est comparé en minuscules, sans espaces autour.
     *
     * Sans ça, « Laurent » et « laurent » seraient deux comptes distincts, et le second
     * serait créé par erreur par quelqu'un qui croit se connecter au premier. C'est aussi
     * ce qui rend l'index unique réellement unique du point de vue de l'utilisateur.
     */
    public static function normaliser(string $identifiant): string
    {
        return mb_strtolower(trim($identifiant));
    }

    public static function parIdentifiant(string $identifiant): ?array
    {
        $ligne = Db::one('SELECT * FROM comptes WHERE identifiant = ?',
            [self::normaliser($identifiant)]);

        return $ligne ?: null;
    }

    /**
     * Y a-t-il au moins un compte utilisable ? Détermine l'écran de connexion à montrer.
     *
     * LA QUESTION EST POSÉE AVANT LA MIGRATION, et c'est ce qui a cassé le parcours de
     * bout en bout. L'écran de connexion s'affiche forcément avant `Db::migrate()`, qui
     * n'est appelée qu'une fois le visiteur authentifié : sur une instance fraîchement
     * installée, la table « comptes » n'existe donc pas encore et la requête échouait, ce
     * qui rendait l'écran de connexion INACCESSIBLE. La panne la plus bête possible : on ne
     * peut plus entrer parce qu'on demande s'il y a des comptes.
     *
     * Une table absente vaut « aucun compte », ce qui est la vérité et fait retomber sur
     * l'écran d'origine à un seul champ.
     */
    public static function existe(): bool
    {
        try {
            return (int) Db::val('SELECT COUNT(*) FROM comptes WHERE actif = 1') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Vérifie un couple identifiant / mot de passe.
     *
     * LA TEMPORISATION EST LA MÊME QUE LE COMPTE EXISTE OU NON, et le hachage est calculé
     * même sur un identifiant inconnu. Sans ça, le temps de réponse dirait quels
     * identifiants existent, ce qui transforme l'écran de connexion en annuaire.
     */
    public static function verifier(string $identifiant, string $motDePasse): ?array
    {
        $compte = self::parIdentifiant($identifiant);

        // Empreinte de comparaison quand le compte n'existe pas : password_verify tourne
        // quand même, pour le même coût de calcul.
        $empreinte = (string) ($compte['mot_de_passe'] ?? '')
            // Empreinte d'un secret aléatoire jeté : elle ne peut correspondre à rien, et
            // c'est un VRAI bcrypt, pas une chaîne inventée. Une empreinte malformée ferait
            // sortir password_verify immédiatement, et la temporisation qu'on cherche à
            // égaliser ne le serait plus du tout.
            ?: '$2y$12$XMElCotH8Z2WjkjSaUAe9.onQHN/Q5cV//4NLyg.wFz2LXOnavF7O';

        $bon = password_verify($motDePasse, $empreinte);

        if (!$bon || $compte === null || (int) $compte['actif'] !== 1) {
            return null;
        }

        return $compte;
    }

    public static function marquerAcces(int $id): void
    {
        Db::update('comptes', ['dernier_acces_le' => date('Y-m-d H:i:s')], 'id = :__i', ['__i' => $id]);
    }

    /**
     * Ouvre une réinitialisation et rend le jeton EN CLAIR, une seule fois.
     *
     * Rend null quand le compte est introuvable ou sans adresse : l'appelant doit alors
     * répondre exactement la même chose que dans le cas nominal, sans quoi l'écran
     * « mot de passe oublié » devient un moyen de tester quels comptes existent.
     */
    public static function ouvrirReinit(string $identifiant): ?array
    {
        $compte = self::parIdentifiant($identifiant);

        if ($compte === null || (int) $compte['actif'] !== 1 || ($compte['courriel'] ?? '') === '') {
            return null;
        }

        $jeton = bin2hex(random_bytes(24));

        Db::update('comptes', [
            'jeton_reinit'    => hash('sha256', $jeton),
            'jeton_expire_le' => date('Y-m-d H:i:s', time() + self::REINIT_TTL),
        ], 'id = :__i', ['__i' => (int) $compte['id']]);

        return ['compte' => $compte, 'jeton' => $jeton];
    }

    /**
     * Consomme un jeton et pose le nouveau mot de passe.
     *
     * LE JETON EST À USAGE UNIQUE, et il est effacé même quand le nouveau mot de passe est
     * refusé pour sa longueur : sans ça, un jeton capturé resterait utilisable pendant une
     * heure après qu'on l'a vu échouer une fois.
     */
    public static function reinitialiser(string $jeton, string $nouveauMotDePasse): bool
    {
        if ($jeton === '') {
            return false;
        }

        $compte = Db::one(
            'SELECT * FROM comptes WHERE jeton_reinit = ? AND actif = 1',
            [hash('sha256', $jeton)]);

        if (!$compte) {
            return false;
        }

        $expire = strtotime((string) ($compte['jeton_expire_le'] ?? '')) ?: 0;
        $valide = $expire > time() && mb_strlen($nouveauMotDePasse) >= self::MDP_MIN;

        $champs = ['jeton_reinit' => null, 'jeton_expire_le' => null];

        if ($valide) {
            $champs['mot_de_passe'] = password_hash($nouveauMotDePasse, PASSWORD_DEFAULT);
        }

        Db::update('comptes', $champs, 'id = :__i', ['__i' => (int) $compte['id']]);

        return $valide;
    }

    /**
     * Consigne une tentative, réussie ou non.
     *
     * LES ÉCHECS SONT CONSIGNÉS AU MÊME ENDROIT QUE LES SUCCÈS. Une série d'échecs sur un
     * identifiant valide est le seul signal qui distingue une tentative d'intrusion d'un
     * mot de passe oublié, et elle n'apparaît que si les deux sont visibles ensemble.
     *
     * ON N'ENREGISTRE JAMAIS LE MOT DE PASSE, même dans le champ « identifiant » : une
     * faute de frappe met parfois le secret dans le champ du dessus, et ce journal
     * deviendrait alors une liste de mots de passe en clair.
     */
    public static function consigner(
        string $voie,
        bool $reussie,
        ?int $compteId = null,
        string $identifiant = '',
    ): void {
        Db::insert('connexions', [
            'compte_id'   => $compteId,
            'identifiant' => $identifiant !== '' ? str_cut(self::normaliser($identifiant), 190) : null,
            'voie'        => $voie,
            'reussie'     => $reussie ? 1 : 0,
            'ip'          => Auth::ip(),
            'agent'       => str_cut((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
            'ts'          => date('Y-m-d H:i:s'),
        ]);
    }
}
