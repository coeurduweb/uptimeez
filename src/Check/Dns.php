<?php
declare(strict_types=1);

namespace Uptimeez\Check;

/**
 * Un enregistrement DNS existe-t-il, et vaut-il ce qu'il devrait ?
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CE CONTRÔLE MÉRITE D'EXISTER À CÔTÉ DE LA SURVEILLANCE D'UNE PAGE
 * ------------------------------------------------------------------------------
 *
 * Une page qui répond prouve que la zone DNS marche. L'inverse n'est pas vrai, et c'est là
 * qu'est l'intérêt : les enregistrements qui ne servent AUCUNE page ne sont surveillés par
 * personne. Un MX supprimé par erreur ne casse pas le site, il fait disparaître le courrier
 * sans qu'aucun visiteur ne s'en aperçoive. Un TXT de validation retiré casse une
 * signature. Un NS changé chez le registraire déplace toute la zone.
 *
 * ------------------------------------------------------------------------------
 * DEUX QUESTIONS, ET LA SECONDE EST FACULTATIVE
 * ------------------------------------------------------------------------------
 *
 * « Y a-t-il une réponse » se contrôle sans rien configurer. « Vaut-elle ceci » demande une
 * valeur attendue, et c'est celle qui attrape le changement silencieux : un A qui pointe
 * ailleurs répond parfaitement.
 *
 * LA COMPARAISON EST UNE INCLUSION ET NON UNE ÉGALITÉ, parce qu'un enregistrement rend
 * plusieurs champs selon son type : un MX porte une cible et une priorité, un SOA une
 * dizaine de valeurs. Exiger l'égalité obligerait l'exploitant à recopier une syntaxe
 * exacte qu'il n'a aucune raison de connaître.
 */
final class Dns
{
    /** Les types qu'on sait interroger, et rien de plus : le reste est refusé plutôt que deviné. */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA', 'CAA'];

    /**
     * @return array{checked:bool,found:bool,values:array<int,string>,ms:int,error:?string}
     */
    public static function probe(string $nom, string $type): array
    {
        $nom = trim(rtrim($nom, '.'));
        $type = strtoupper(trim($type));

        if ($nom === '' || ! in_array($type, self::TYPES, true)) {
            return ['checked' => false, 'found' => false, 'values' => [], 'ms' => 0,
                    'error' => t('Nom ou type d\'enregistrement invalide')];
        }

        $constantes = [
            'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX,
            'NS' => DNS_NS, 'TXT' => DNS_TXT, 'SOA' => DNS_SOA, 'CAA' => DNS_CAA,
        ];

        $debut = microtime(true);
        $reponses = @dns_get_record($nom, $constantes[$type]);
        $ms = (int) round((microtime(true) - $debut) * 1000);

        if ($reponses === false) {
            return ['checked' => true, 'found' => false, 'values' => [], 'ms' => $ms,
                    'error' => t('la résolution a échoué')];
        }

        // LES VALEURS SONT APLATIES EN TEXTE, une par enregistrement, parce que c'est sous
        // cette forme qu'un exploitant les reconnaît : « 203.0.113.10 », « 10 mx.exemple.fr ».
        // Rendre le tableau brut de PHP obligerait chaque appelant à savoir quel champ porte
        // la valeur selon le type, et cette connaissance appartient ici.
        $valeurs = [];

        foreach ($reponses as $r) {
            $valeurs[] = match ($type) {
                'A' => (string) ($r['ip'] ?? ''),
                'AAAA' => (string) ($r['ipv6'] ?? ''),
                'CNAME', 'NS' => rtrim((string) ($r['target'] ?? ''), '.'),
                'MX' => trim(((string) ($r['pri'] ?? '')) . ' ' . rtrim((string) ($r['target'] ?? ''), '.')),
                'TXT' => (string) ($r['txt'] ?? ''),
                'SOA' => rtrim((string) ($r['mname'] ?? ''), '.'),
                'CAA' => trim(((string) ($r['flags'] ?? '')) . ' ' . ((string) ($r['tag'] ?? ''))
                              . ' ' . ((string) ($r['value'] ?? ''))),
                default => '',
            };
        }

        $valeurs = array_values(array_filter(array_map('trim', $valeurs), static fn (string $v): bool => $v !== ''));

        return ['checked' => true, 'found' => $valeurs !== [], 'values' => $valeurs, 'ms' => $ms,
                'error' => null];
    }
}
