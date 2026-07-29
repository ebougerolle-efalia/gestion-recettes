<?php
namespace App\Service;

/**
 * Conversions entre les unités de l'application (kg, g, litre, pièce).
 *
 * Extrait de CostCalculator, où la logique était privée, pour être partagé avec
 * la réception des factures : un fournisseur qui facture au gramme ou à la
 * tonne doit voir son prix ramené à l'unité de base de l'ingrédient, sinon le
 * prix enregistré est faux d'un facteur mille.
 */
class UnitConverter
{
    /** Conversion exacte. */
    public const OK = 'ok';
    /** Conversion approximative (litre↔kg à densité 1). */
    public const APPROX = 'approx';
    /** Conversion impossible (ex. pièce → litre sans poids unitaire). */
    public const NONE = 'none';

    /**
     * Statut de conversion entre deux unités.
     *
     * @param float|null $unitWeightG poids d'une pièce en grammes, s'il est connu
     */
    public function status(string $from, string $to, ?float $unitWeightG = null): string
    {
        if ($from === $to)                        return self::OK;
        if ($from === 'g'     && $to === 'kg')    return self::OK;
        if ($from === 'kg'    && $to === 'g')     return self::OK;
        if ($from === 'litre' && $to === 'kg')    return self::APPROX;
        if ($from === 'kg'    && $to === 'litre') return self::APPROX;

        // Pont pièce ↔ masse, uniquement si le poids unitaire est renseigné.
        if ($unitWeightG !== null && $unitWeightG > 0) {
            if ($from === 'piece' && ($to === 'g' || $to === 'kg'))   return self::OK;
            if (($from === 'g' || $from === 'kg') && $to === 'piece') return self::OK;
        }

        return self::NONE;
    }

    /** Facteur multiplicatif d'une quantité. Null si la conversion est impossible. */
    public function factor(string $from, string $to, ?float $unitWeightG = null): ?float
    {
        if ($this->status($from, $to, $unitWeightG) === self::NONE) {
            return null;
        }

        if ($from === $to)                        return 1.0;
        if ($from === 'g'     && $to === 'kg')    return 0.001;
        if ($from === 'kg'    && $to === 'g')     return 1000.0;
        if ($from === 'litre' && $to === 'kg')    return 1.0; // densité = 1 (approx)
        if ($from === 'kg'    && $to === 'litre') return 1.0;

        if ($unitWeightG !== null && $unitWeightG > 0) {
            if ($from === 'piece' && $to === 'g')     return $unitWeightG;
            if ($from === 'piece' && $to === 'kg')    return $unitWeightG / 1000.0;
            if ($from === 'g'     && $to === 'piece') return 1.0 / $unitWeightG;
            if ($from === 'kg'    && $to === 'piece') return 1000.0 / $unitWeightG;
        }

        return 1.0;
    }

    /**
     * Convertit une quantité. Retourne 0.0 si la conversion est impossible : le
     * coût de la ligne concernée sera alors nul et une alerte sera levée, plutôt
     * qu'un chiffre inventé.
     */
    public function convertQty(float $qty, string $from, string $to, ?float $unitWeightG = null): float
    {
        $factor = $this->factor($from, $to, $unitWeightG);

        return $factor !== null ? $qty * $factor : 0.0;
    }

    /**
     * Convertit un PRIX unitaire, dont le facteur est l'inverse de celui d'une
     * quantité : 0,0084 €/g fait 8,40 €/kg, alors que 1 g fait 0,001 kg.
     *
     * Retourne null quand la conversion est impossible — auquel cas il faut
     * demander le prix à l'utilisateur, pas en fabriquer un.
     */
    public function convertPrice(float $price, string $from, string $to, ?float $unitWeightG = null): ?float
    {
        $factor = $this->factor($from, $to, $unitWeightG);

        if ($factor === null || $factor == 0.0) {
            return null;
        }

        return $price / $factor;
    }

    /** Libellé court d'une unité, pour l'affichage. */
    public function label(string $unit): string
    {
        return match ($unit) {
            'piece' => 'pièce',
            default => $unit,
        };
    }
}
