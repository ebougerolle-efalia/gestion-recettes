<?php
namespace App\Service;

/**
 * Comparaison de libellés par sacs de mots.
 *
 * similar_text() compare des suites de caractères sans comprendre les mots :
 * « Epaule porc desossee » y ressemble davantage à « Foie de porc » qu'à
 * « Épaule de porc désossée ». Compter les mots communs supprime ce genre de
 * faux positif.
 *
 * La normalisation reprend celle de CiqualMatcher, éprouvée sur 3 500 aliments.
 * La duplication est assumée : les deux rapprochements n'ont ni les mêmes mots
 * vides ni les mêmes bonus, et fusionner leurs scores ferait courir le risque
 * de dégrader un appariement Ciqual déjà validé.
 */
class TextMatching
{
    /** Réduit une chaîne en ASCII minuscule (accents et ligatures dépliés). */
    public static function normalize(string $s): string
    {
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; Lower');
            if ($tr) {
                $s = $tr->transliterate($s) ?: $s;
            }
        } else {
            $s = strtr($s, ['œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae']);
            $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
            $s = mb_strtolower($s);
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Mots significatifs d'un libellé : normalisés, sans mots vides, sans
     * nombres nus — un « 500 » de conditionnement ne dit rien du produit.
     *
     * @param string[] $stopwords
     * @return string[]
     */
    public static function tokenize(string $s, array $stopwords = []): array
    {
        $tokens = array_filter(
            explode(' ', self::normalize($s)),
            fn (string $t) => $t !== ''
                && mb_strlen($t) > 1
                && !ctype_digit($t)
                && !in_array($t, $stopwords, true)
        );

        return array_values(array_unique($tokens));
    }

    /**
     * Deux mots concordent s'ils sont égaux, ou si l'un est l'autre suivi d'une
     * marque de pluriel ou de féminin. Un simple test de préfixe serait trop
     * large : « port » deviendrait « porc », et des frais de port se
     * transformeraient en poitrine de porc.
     */
    public static function tokensMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (mb_strlen($short) < 4 || !str_starts_with($long, $short)) {
            return false;
        }

        return in_array(mb_substr($long, mb_strlen($short)), ['s', 'e', 'es', 'x'], true);
    }

    /**
     * Similarité entre deux sacs de mots, de 0 à 1.
     *
     * Coefficient de Dice, majoré quand le mot de tête du candidat est
     * retrouvé — c'est lui qui porte le sens — et minoré à proportion des mots
     * du candidat restés sans écho, signe qu'il désigne autre chose.
     *
     * @param string[] $needle    mots du libellé cherché
     * @param string[] $candidate mots du libellé candidat
     */
    public static function score(array $needle, array $candidate): float
    {
        if (!$needle || !$candidate) {
            return 0.0;
        }

        $common = 0;
        foreach ($needle as $n) {
            foreach ($candidate as $c) {
                if (self::tokensMatch($n, $c)) {
                    $common++;
                    break;
                }
            }
        }

        if ($common === 0) {
            return 0.0;
        }

        $score = (2 * $common) / (count($needle) + count($candidate));

        foreach ($needle as $n) {
            if (self::tokensMatch($n, $candidate[0])) {
                $score += 0.15;
                break;
            }
        }

        $unmatched = 0;
        foreach ($candidate as $c) {
            $found = false;
            foreach ($needle as $n) {
                if (self::tokensMatch($n, $c)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $unmatched++;
            }
        }
        $score -= 0.20 * ($unmatched / count($candidate));

        return max(0.0, min(1.0, $score));
    }
}
