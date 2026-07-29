<?php
namespace App\Service;

use App\Entity\CiqualFood;
use App\Entity\Ingredient;
use App\Repository\CiqualFoodRepository;
use Transliterator;

/**
 * Appariement d'un ingrédient avec un aliment de la table Ciqual.
 *
 * La nomenclature Ciqual est « tête, précision, état » (« Porc, épaule, crue »)
 * alors qu'un ingrédient d'atelier est nommé librement (« Épaule de porc
 * désossée ») : une recherche par sous-chaîne ne peut pas les rapprocher. On
 * compare donc des sacs de mots normalisés, avec un bonus sur le mot de tête
 * Ciqual, qui porte le sens dans cette nomenclature.
 *
 * Le calcul est fait en PHP pour rester portable (SQLite, MySQL, PostgreSQL).
 * Sur PostgreSQL, la présélection pourra être remplacée par pg_trgm sans
 * toucher à cette classe : seule change la méthode du repository.
 */
class CiqualMatcher
{
    /** Score en dessous duquel on ne propose rien du tout. */
    public const MIN_SUGGEST = 0.35;

    /**
     * Score à partir duquel un rattachement automatique est acceptable.
     * Calé pour privilégier la précision : mieux vaut laisser un ingrédient
     * non rattaché qu'associer un aliment faux, dont les valeurs partiraient
     * en déclaration nutritionnelle.
     */
    public const MIN_AUTO = 0.72;

    /** Mots vides et mentions commerciales sans valeur discriminante. */
    private const STOPWORDS = [
        'de', 'du', 'des', 'd', 'a', 'au', 'aux', 'en', 'et', 'ou', 'le', 'la', 'les', 'l',
        'sans', 'avec', 'pour', 'sur', 'type', 'sorte', 'qualite', 'superieur', 'sup', 'extra',
        'bio', 'aop', 'aoc', 'igp', 'label', 'rouge', 'fermier', 'fermiere', 'plein', 'air',
        'mg', 'ml', 'cl', 'kg', 'g', 'l', 'calibre', 'pce', 'piece', 'pieces', 'environ',
        'vs', 'vsop', 'xo', // mentions de vieillissement des eaux-de-vie
        'nature', 'naturel', 'naturelle', 'maison', 'artisanal', 'artisanale', 'premium',
    ];

    /**
     * Indices de groupe Ciqual déduits du nom de catégorie de l'ingrédient.
     * Utilisés comme bonus, jamais comme filtre : le beurre est en « matières
     * grasses » alors que sa catégorie d'atelier est « Produits laitiers ».
     */
    private const GROUP_HINTS = [
        'viande'   => 'viandes, oeufs, poissons',
        'volaille' => 'viandes, oeufs, poissons',
        'charcut'  => 'viandes, oeufs, poissons',
        'poisson'  => 'viandes, oeufs, poissons',
        'oeuf'     => 'viandes, oeufs, poissons',
        'lait'     => 'produits laitiers',
        'fromage'  => 'produits laitiers',
        'cereal'   => 'produits céréaliers',
        'farine'   => 'produits céréaliers',
        'pain'     => 'produits céréaliers',
        'sucre'    => 'produits sucrés',
        'chocolat' => 'produits sucrés',
        'fruit'    => 'fruits, légumes, légumineuses et oléagineux',
        'legume'   => 'fruits, légumes, légumineuses et oléagineux',
        'epice'    => 'aides culinaires et ingrédients divers',
        'additif'  => 'aides culinaires et ingrédients divers',
        'ferment'  => 'aides culinaires et ingrédients divers',
        'condiment' => 'aides culinaires et ingrédients divers',
        'huile'    => 'matières grasses',
        'graisse'  => 'matières grasses',
        'boisson'  => 'eaux et autres boissons',
        'alcool'   => 'eaux et autres boissons',
    ];

    /** Catégories non comestibles : aucun appariement à tenter. */
    private const SKIP_CATEGORIES = ['emballage', 'consommable', 'materiel', 'fourniture'];

    public function __construct(private CiqualFoodRepository $repo) {}

    /** Réduit une chaîne en ASCII minuscule (accents et ligatures dépliés). */
    public static function normalize(string $s): string
    {
        if (class_exists(Transliterator::class)) {
            $tr = Transliterator::create('Any-Latin; Latin-ASCII; Lower');
            if ($tr) {
                $s = $tr->transliterate($s) ?: $s;
            }
        } else {
            // Repli sans intl : « œ » et « æ » doivent être dépliés à la main,
            // iconv les remplacerait par « ? ».
            $s = strtr($s, ['œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae']);
            $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
            $s = mb_strtolower($s);
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Mots significatifs d'un libellé (normalisés, sans mots vides ni nombres). */
    public static function tokenize(string $s): array
    {
        $tokens = array_filter(
            explode(' ', self::normalize($s)),
            fn (string $t) => $t !== ''
                && mb_strlen($t) > 1
                && !ctype_digit($t)
                && !in_array($t, self::STOPWORDS, true)
        );

        return array_values(array_unique($tokens));
    }

    /**
     * Meilleurs aliments Ciqual pour un libellé d'ingrédient.
     *
     * @return array<int,array{food:CiqualFood,score:float}> trié du meilleur au moins bon
     */
    public function suggest(string $name, ?string $categoryName = null, int $limit = 5): array
    {
        if ($this->isSkippedCategory($categoryName)) {
            return [];
        }

        $tokens = self::tokenize($name);
        if (!$tokens) {
            return [];
        }

        $hintGroup = $this->groupHint($categoryName);
        $scored    = [];

        foreach ($this->repo->findCandidatesByTokens($tokens) as $food) {
            $score = $this->score($tokens, $food, $hintGroup);
            if ($score >= self::MIN_SUGGEST) {
                $scored[] = ['food' => $food, 'score' => round($score, 3)];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Meilleure correspondance si elle dépasse le seuil, sinon null.
     *
     * @return array{food:CiqualFood,score:float}|null
     */
    public function bestMatch(string $name, ?string $categoryName = null, float $minScore = self::MIN_AUTO): ?array
    {
        $best = $this->suggest($name, $categoryName, 1)[0] ?? null;

        return ($best && $best['score'] >= $minScore) ? $best : null;
    }

    /** Raccourci sur une entité Ingredient (utilise sa catégorie comme indice). */
    public function suggestForIngredient(Ingredient $ing, int $limit = 5): array
    {
        return $this->suggest($ing->getName(), $ing->getCategory()?->getName(), $limit);
    }

    /**
     * Meilleur score de l'aliment, en tenant compte de ses libellés alternatifs.
     *
     * Ciqual énumère les synonymes avec « ou » (« Calmar ou calamar ou
     * encornet », « Eau de vie de vin type armagnac ou cognac »). Confronter
     * l'ingrédient à l'énumération entière fait chuter toute mesure de
     * similarité : on compare donc à chaque alternative, et on garde la
     * meilleure.
     */
    private function score(array $ingTokens, CiqualFood $food, ?string $hintGroup): float
    {
        $variants = $this->foodVariants($food);
        $full     = array_shift($variants);

        $best = $this->scoreAgainst($ingTokens, $full, $food, $hintGroup, false);
        foreach ($variants as $foodTokens) {
            $best = max($best, $this->scoreAgainst($ingTokens, $foodTokens, $food, $hintGroup, true));
        }

        return $best;
    }

    /**
     * Jeux de mots à comparer pour un aliment : le libellé complet, puis chaque
     * alternative séparée par « ou ».
     *
     * @return array<int,string[]>
     */
    private function foodVariants(CiqualFood $food): array
    {
        $norm = $food->getNomNorm() ?? self::normalize($food->getNom());

        $variants = [self::tokenize($norm)];
        if (str_contains($norm, ' ou ')) {
            foreach (explode(' ou ', $norm) as $part) {
                if ($tokens = self::tokenize($part)) {
                    $variants[] = $tokens;
                }
            }
        }

        return array_filter($variants);
    }

    /**
     * Similarité entre deux sacs de mots, puis bonus métier.
     *
     * Sur le libellé complet : coefficient de Dice. Sur une alternative issue
     * d'un « ou » ($variant), on divise par le plus grand des deux sacs : un
     * fragment d'énumération est souvent un simple qualificatif (« au riz ou à
     * la semoule »), et Dice sur un sac d'un seul mot le ferait gagner à tort
     * contre n'importe quel ingrédient contenant ce mot.
     */
    private function scoreAgainst(array $ingTokens, array $foodTokens, CiqualFood $food, ?string $hintGroup, bool $variant): float
    {
        if (!$foodTokens) {
            return 0.0;
        }

        $common        = 0;
        $foodUnmatched = count($foodTokens);
        foreach ($ingTokens as $t) {
            foreach ($foodTokens as $f) {
                if ($this->tokensMatch($t, $f)) {
                    $common++;
                    $foodUnmatched--;
                    break;
                }
            }
        }

        $score = $variant
            ? $common / max(count($ingTokens), count($foodTokens))
            : (2 * $common) / (count($ingTokens) + count($foodTokens));

        // Les mots Ciqual restés sans écho désignent souvent un autre aliment
        // (« Crème de camembert » pour « Crème liquide »). Pénalité relative,
        // pour ne pas désavantager les libellés Ciqual naturellement longs.
        $score -= 0.20 * (max(0, $foodUnmatched) / count($foodTokens));

        // Sur une alternative, l'exigence est symétrique : se présenter comme le
        // même aliment suppose d'expliquer tout le nom de l'ingrédient. Sans
        // cela, « (tablette ou pépites) » l'emporterait sur « pépites de
        // chocolat » en ignorant le mot « chocolat ».
        if ($variant) {
            $score -= 0.20 * ((count($ingTokens) - $common) / count($ingTokens));
        }

        // Le premier mot Ciqual est la tête de nomenclature : « Porc, épaule »
        // doit primer sur « Graisse de porc » pour un ingrédient « épaule de porc ».
        $head = $foodTokens[0];
        foreach ($ingTokens as $t) {
            if ($this->tokensMatch($t, $head)) {
                $score += 0.15;
                break;
            }
        }

        if ($hintGroup && $food->getGroupe() && self::normalize($food->getGroupe()) === self::normalize($hintGroup)) {
            $score += 0.10;
        }

        // Un code alphanumérique commun (T55, T130) identifie le produit bien
        // plus sûrement qu'un mot ordinaire.
        foreach ($ingTokens as $t) {
            if (preg_match('/\d/', $t) && in_array($t, $foodTokens, true)) {
                $score += 0.10;
                break;
            }
        }

        // À libellé équivalent, l'état cru est la bonne base de calcul : la
        // recette applique ensuite ses propres pertes et sa cuisson.
        $foodNorm = $food->getNomNorm() ?? self::normalize($food->getNom());
        if (preg_match('/\b(cru|crue|crus|crues)\b/', $foodNorm)) {
            $score += 0.05;
        } elseif (preg_match('/\b(cuit|cuite|frit|frite|grille|grillee|roti|rotie|braise|braisee|poele|poelee)\b/', $foodNorm)) {
            $score -= 0.10;
        }

        // Idem pour le salage : un beurre salé n'est pas un beurre doux.
        if (preg_match('/\b(sale|salee|sucre|sucree)\b/', $foodNorm)
            && !preg_match('/\b(sale|salee|sucre|sucree)\b/', self::normalize(implode(' ', $ingTokens)))) {
            $score -= 0.05;
        }

        // « aliment moyen » est une moyenne de catégorie, utile en dernier recours
        // seulement : à score égal, un aliment précis vaut mieux.
        if (str_contains($foodNorm, 'aliment moyen')) {
            $score -= 0.05;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Deux mots concordent s'ils sont égaux, ou si l'un est l'autre suivi d'une
     * marque de pluriel ou de féminin. Un simple test de préfixe serait trop
     * large : « lait » préfixe « laitue ».
     */
    private function tokensMatch(string $a, string $b): bool
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

    private function groupHint(?string $categoryName): ?string
    {
        if (!$categoryName) {
            return null;
        }
        $norm = self::normalize($categoryName);

        foreach (self::GROUP_HINTS as $needle => $groupe) {
            if (str_contains($norm, $needle)) {
                return $groupe;
            }
        }

        return null;
    }

    private function isSkippedCategory(?string $categoryName): bool
    {
        if (!$categoryName) {
            return false;
        }
        $norm = self::normalize($categoryName);

        foreach (self::SKIP_CATEGORIES as $needle) {
            if (str_contains($norm, $needle)) {
                return true;
            }
        }

        return false;
    }
}
