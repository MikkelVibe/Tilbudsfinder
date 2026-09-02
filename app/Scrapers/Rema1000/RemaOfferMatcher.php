<?php

namespace App\Scrapers\Rema1000;

use App\Normalization\UnitAliasMap;
use App\Normalization\ValueObjects\PackageQuantity;
use App\Scrapers\Rema1000\DTO\RemaMatchResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class RemaOfferMatcher
{
    public function __construct(
        private readonly UnitAliasMap $unitAliasMap = new UnitAliasMap,
    ) {}

    /**
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $tjekOffers
     * @param  list<array<string, mixed>>  $products
     */
    public function match(array $catalog, array $tjekOffers, array $products): RemaMatchResult
    {
        $catalogId = trim((string) ($catalog['id'] ?? ''));
        $outcomes = [];

        foreach ($tjekOffers as $offer) {
            $candidates = [];

            foreach ($products as $product) {
                foreach ($this->advertisedPrices($product) as $price) {
                    $candidate = $this->scoreCandidate($offer, $product, $price);

                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }

            usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $confident = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['score'] >= 70 && $candidate['heading_hits'] > 0));

            if ($confident === []) {
                $status = $candidates === [] ? 'missing' : 'ambiguous';
                $matches = [];
            } else {
                $topScore = $confident[0]['score'];
                $matches = array_values(array_filter($confident, static fn (array $candidate): bool => $candidate['score'] >= $topScore - 6));
                $status = count($matches) <= 12 ? 'matched' : 'ambiguous';

                if ($status === 'ambiguous') {
                    $matches = [];
                }
            }

            $outcomes[] = [
                'offer' => $offer,
                'status' => $status,
                'matches' => $matches,
                'candidates' => array_slice($candidates, 0, 20),
            ];
        }

        [$outcomes, $resolvedConflictCount] = $this->resolveProductConflicts($outcomes);
        $matchedOffers = [];
        $issues = [];
        $counts = ['matched' => 0, 'ambiguous' => 0, 'missing' => 0];

        foreach ($outcomes as $outcome) {
            $counts[$outcome['status']]++;

            if ($outcome['status'] === 'matched') {
                foreach ($outcome['matches'] as $match) {
                    $matchedOffers[] = [
                        'tjek_offer' => $outcome['offer'],
                        'rema_product' => $match['product'],
                        'advertised_price' => $match['price'],
                        'score' => $match['score'],
                    ];
                }

                continue;
            }

            $offerId = trim((string) ($outcome['offer']['id'] ?? ''));
            $code = $outcome['status'] === 'missing' ? 'missing_rema_product' : 'ambiguous_rema_match';
            $issues[] = [
                'code' => $code,
                'source_catalog_id' => $catalogId,
                'source_offer_id' => $offerId,
                'message' => $outcome['status'] === 'missing'
                    ? 'Tjek offer has no REMA advertised product with matching price and validity.'
                    : 'Tjek offer could not be mapped to REMA products with sufficient confidence.',
                'context' => [
                    'heading' => trim((string) ($outcome['offer']['heading'] ?? '')),
                    'candidate_count' => count($outcome['candidates']),
                    'candidate_product_ids' => array_column($outcome['candidates'], 'product_id'),
                    'candidate_scores' => array_column($outcome['candidates'], 'score', 'product_id'),
                ],
            ];
        }

        return new RemaMatchResult(
            matchedOffers: $matchedOffers,
            issues: $issues,
            matchedTjekOfferCount: $counts['matched'],
            ambiguousTjekOfferCount: $counts['ambiguous'],
            missingTjekOfferCount: $counts['missing'],
            resolvedConflictCount: $resolvedConflictCount,
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $price
     * @return array<string, mixed>|null
     */
    private function scoreCandidate(array $offer, array $product, array $price): ?array
    {
        $offerPrice = $this->decimal(Arr::get($offer, 'pricing.price'));
        $productPrice = $this->decimal(Arr::get($price, 'price'));

        if ($offerPrice === null || $productPrice === null || ! $offerPrice->isEqualTo($productPrice)) {
            return null;
        }

        $offerStart = $this->date(Arr::get($offer, 'run_from'));
        $offerEnd = $this->date(Arr::get($offer, 'run_till'));
        $priceStart = $this->date(Arr::get($price, 'starting_at'));
        $priceEnd = $this->date(Arr::get($price, 'ending_at'));

        if ($offerStart === null || $offerEnd === null || $priceStart === null || $priceEnd === null || $offerStart->greaterThan($priceEnd) || $priceStart->greaterThan($offerEnd)) {
            return null;
        }

        $headingTokens = $this->tokens((string) Arr::get($offer, 'heading', ''));
        $offerTokens = $this->tokens(trim((string) Arr::get($offer, 'heading', '').' '.(string) Arr::get($offer, 'description', '')));
        $productTokens = $this->tokens(trim((string) Arr::get($product, 'name', '').' '.(string) Arr::get($product, 'underline', '')));
        $headingHits = count(array_intersect($headingTokens, $productTokens));
        $allHits = count(array_intersect($offerTokens, $productTokens));
        $textScore = min(50, ($headingHits * 10) + (max(0, $allHits - $headingHits) * 3));
        $quantityMatch = $this->quantitiesMatch($offer, $price);

        return [
            'product_id' => (string) Arr::get($product, 'id', ''),
            'score' => 50 + $textScore + ($quantityMatch ? 20 : 0),
            'heading_hits' => $headingHits,
            'quantity_match' => $quantityMatch,
            'product' => $product,
            'price' => $price,
        ];
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $price
     */
    private function quantitiesMatch(array $offer, array $price): bool
    {
        $offerSize = $this->decimal(Arr::get($offer, 'quantity.size.from'));
        $offerUnitText = (string) Arr::get($offer, 'quantity.unit.symbol', '');
        $offerUnit = $this->unitAliasMap->normalize($offerUnitText);
        $comparePrice = $this->decimal(Arr::get($price, 'compare_unit_price'));
        $productPrice = $this->decimal(Arr::get($price, 'price'));
        $compareUnit = $this->unitAliasMap->normalize((string) Arr::get($price, 'compare_unit', ''));

        if ($offerSize === null || $offerUnit === null || $compareUnit === null || $comparePrice === null || $comparePrice->isLessThanOrEqualTo(0) || $productPrice === null) {
            return false;
        }

        $offerQuantity = new PackageQuantity($offerSize, $offerUnit, $offerUnitText);

        if ($offerQuantity->compareUnit() !== $compareUnit->compareUnit()) {
            return false;
        }

        $offerAmount = $offerQuantity->normalizedAmount();
        $inferredAmount = $productPrice->dividedBy($comparePrice, 6, RoundingMode::HALF_UP);
        $tolerance = BigDecimal::of('0.01')->max($offerAmount->multipliedBy('0.04'));

        return $inferredAmount->minus($offerAmount)->abs()->isLessThanOrEqualTo($tolerance);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array{list<array<string, mixed>>, int}
     */
    private function resolveProductConflicts(array $outcomes): array
    {
        $assignments = [];

        foreach ($outcomes as $outcomeIndex => $outcome) {
            foreach ($outcome['matches'] as $match) {
                $assignments[$match['product_id']][] = ['outcome_index' => $outcomeIndex, 'score' => $match['score']];
            }
        }

        $resolved = 0;

        foreach ($assignments as $productId => $productAssignments) {
            if (count($productAssignments) < 2) {
                continue;
            }

            $productId = (string) $productId;
            usort($productAssignments, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $winnerIndex = $productAssignments[0]['score'] >= $productAssignments[1]['score'] + 7
                ? $productAssignments[0]['outcome_index']
                : null;

            if ($winnerIndex !== null) {
                $resolved++;
            }

            foreach ($productAssignments as $assignment) {
                $outcomeIndex = $assignment['outcome_index'];

                if ($winnerIndex !== null && $outcomeIndex === $winnerIndex) {
                    continue;
                }

                $outcomes[$outcomeIndex]['matches'] = array_values(array_filter(
                    $outcomes[$outcomeIndex]['matches'],
                    static fn (array $match): bool => $match['product_id'] !== $productId,
                ));

                if ($outcomes[$outcomeIndex]['matches'] === []) {
                    $outcomes[$outcomeIndex]['status'] = 'ambiguous';
                }
            }
        }

        return [$outcomes, $resolved];
    }

    /** @return list<array<string, mixed>> */
    private function advertisedPrices(array $product): array
    {
        $prices = Arr::get($product, 'prices', []);

        return is_array($prices) ? array_values(array_filter($prices, static fn (mixed $price): bool => is_array($price) && Arr::get($price, 'is_advertised') === true)) : [];
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $value = strtr(mb_strtolower($value), ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa']);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $stops = ['eller', 'og', 'med', 'uden', 'til', 'fra', 'pr', 'stk', 'pakke', 'gram', 'liter', 'ltr', 'kilo', 'max', 'variant', 'køb', 'flere', 'pris', 'frit', 'valg', 'rema', '1000', 'dansk', 'g', 'gr', 'kg', 'ml', 'cl', 'l'];

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => mb_strlen($part) >= 2 && ! is_numeric($part) && ! in_array($part, $stops, true))));
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        return is_numeric($value) ? BigDecimal::of((string) $value) : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
    }
}
