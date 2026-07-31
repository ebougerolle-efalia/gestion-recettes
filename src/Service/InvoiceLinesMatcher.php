<?php
namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\InvoiceIngredientMapping;
use App\Repository\IngredientRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Associe les libellés de lignes de facture fournisseur
 * aux ingrédients du catalogue, par ordre de priorité :
 *
 *  1. Correspondance exacte dans les mappings mémorisés (score 100)
 *  2. Rapprochement par mots communs, normalisés (score 0-100)
 *  3. Aucune correspondance (score 0)
 *
 * Les associations confirmées par l'utilisateur sont sauvegardées
 * dans invoice_ingredient_mappings pour les prochains imports.
 */
class InvoiceLinesMatcher
{
    /** Score minimal, de 0 à 1, pour proposer une correspondance. */
    private const MATCH_THRESHOLD = 0.55;

    /**
     * Mots vides des libellés fournisseurs : conditionnement, mentions
     * commerciales et unités. « Crème liquide 35% MG - colis de 6 x 1 L » doit
     * se réduire à « creme liquide » pour être comparable au catalogue.
     */
    private const STOPWORDS = [
        'de', 'du', 'des', 'd', 'a', 'au', 'aux', 'en', 'et', 'ou', 'le', 'la', 'les', 'l',
        'sans', 'avec', 'pour', 'par', 'sur', 'type', 'sorte', 'ref', 'reference',
        'frais', 'fraiche', 'fraiches', 'surgele', 'surgelee', 'refrigere', 'refrig',
        'qualite', 'choix', '1er', '2eme', 'extra', 'premium', 'select', 'superieur', 'sup',
        'bio', 'aop', 'aoc', 'igp', 'label', 'rouge', 'francais', 'francaise', 'france',
        'sac', 'sachet', 'colis', 'carton', 'caisse', 'bidon', 'boite', 'barquette', 'seau',
        'pot', 'bocal', 'plaquette', 'bobine', 'lot', 'palette', 'unite', 'piece', 'pieces',
        'kg', 'g', 'gr', 'mg', 'l', 'cl', 'ml', 'litre', 'litres', 'net', 'poids',
        'decoupe', 'decoupage', 'industriel', 'usage', 'alimentaire', 'culinaire',
        'professionnel', 'nature', 'naturelle', 'maison', 'artisanal', 'artisanale',
    ];

    public function __construct(
        private IngredientRepository $ingredientRepo,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Tente de matcher chaque ligne de facture avec un ingrédient du catalogue.
     * Enrichit chaque ligne avec : matched_ingredient, match_score, match_source.
     */
    public function matchLines(array $invoiceLines): array
    {
        $ingredients = $this->ingredientRepo->findBy([], ['name' => 'ASC']);
        $result = [];

        foreach ($invoiceLines as $line) {
            $match = $this->findBestMatch($line['name'], $ingredients);
            $result[] = array_merge($line, [
                'matched_ingredient' => $match['ingredient'],
                'match_score'        => $match['score'],
                'match_source'       => $match['source'],
                // Date effective par défaut = date de la facture (ou aujourd'hui)
                'effective_date'     => date('Y-m-d'),
            ]);
        }

        return $result;
    }

    /**
     * Cherche le meilleur ingrédient correspondant à un libellé de facture.
     */
    private function findBestMatch(string $label, array $ingredients): array
    {
        $normalized = $this->normalize($label);

        // ── 1. Mapping mémorisé ──────────────────────────────────────────────
        $mapping = $this->em->getRepository(InvoiceIngredientMapping::class)
            ->findOneBy(['invoiceLabel' => $normalized]);

        if ($mapping && $mapping->getIngredient()) {
            return [
                'ingredient' => $mapping->getIngredient(),
                'score'      => 100,
                'source'     => 'mapping',
            ];
        }

        // ── 2. Rapprochement par mots ────────────────────────────────────────
        // similar_text() comparait des caractères sans comprendre les mots :
        // « Epaule porc desossee 1er choix » y ressemblait davantage à « Foie de
        // porc » qu'à « Épaule de porc désossée », et « Participation frais de
        // port » devenait de la poitrine de porc. Le comptage de mots communs
        // supprime cette famille d'erreurs.
        $labelTokens = TextMatching::tokenize($label, self::STOPWORDS);
        if (!$labelTokens) {
            return ['ingredient' => null, 'score' => 0, 'source' => 'none'];
        }

        $best      = null;
        $bestScore = 0.0;

        foreach ($ingredients as $ing) {
            $score = TextMatching::score(
                $labelTokens,
                TextMatching::tokenize($ing->getName(), self::STOPWORDS)
            );

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $ing;
            }
        }

        if ($best && $bestScore >= self::MATCH_THRESHOLD) {
            return [
                'ingredient' => $best,
                'score'      => (int) round($bestScore * 100),
                'source'     => 'fuzzy',
            ];
        }

        // ── 3. Aucune correspondance ─────────────────────────────────────────
        return ['ingredient' => null, 'score' => 0, 'source' => 'none'];
    }

    /**
     * Sauvegarde ou met à jour un mapping libellé → ingrédient.
     * Appelé lors de la confirmation de l'import.
     */
    public function saveMapping(string $invoiceLabel, Ingredient $ingredient): void
    {
        $normalized = $this->normalize($invoiceLabel);

        $repo    = $this->em->getRepository(InvoiceIngredientMapping::class);
        $existing = $repo->findOneBy(['invoiceLabel' => $normalized]);

        if ($existing) {
            $existing->setIngredient($ingredient);
            $existing->setConfirmCount($existing->getConfirmCount() + 1);
            $existing->setLastSeen(new \DateTimeImmutable());
        } else {
            $mapping = new InvoiceIngredientMapping();
            $mapping->setInvoiceLabel($normalized);
            $mapping->setIngredient($ingredient);
            $this->em->persist($mapping);
        }
        // flush() sera appelé globalement dans le controller
    }

    /**
     * Normalise un libellé pour la comparaison :
     * - minuscules
     * - suppression des accents
     * - suppression des qualificatifs courants non discriminants
     */
    public function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');

        // Translitération des accents
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        // Supprimer les qualificatifs fréquents sur les factures fournisseurs
        $stopwords = [
            'frais','fraiche','fraisches','fraîche','surgele','surgelé',
            '1er','2eme','choix','qualite','qualité','extra','premium','select',
            'francais','français','france','igp','aop','label rouge',
            'kg','lot de','sac','bidon','boite','barquette','sachet',
            'refrigere','refrig','decoupe','decoupage','industriel',
            'usage','alimentaire','culinaire','professionnel',
            'sans couenne','avec couenne','desossé','desossee',
        ];

        foreach ($stopwords as $word) {
            $s = preg_replace('/\b' . preg_quote($word, '/') . '\b/', '', $s);
        }

        // Normaliser les espaces
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
