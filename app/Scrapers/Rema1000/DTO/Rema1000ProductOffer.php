<?php

namespace App\Scrapers\Rema1000\DTO;

use Illuminate\Support\Arr;

readonly class Rema1000ProductOffer
{
    /**
     * @param  array<string, mixed>  $algolia
     */
    public function __construct(
        public array $algolia,
        public Rema1000ProductDetail $productDetail,
        public Rema1000AdvertisedPrice $advertisedPrice,
    ) {}

    /**
     * @return array{algolia: array<string, mixed>, product_detail: array<string, mixed>, advertised_price: array<string, mixed>}
     */
    public function sourcePayload(): array
    {
        return [
            'algolia' => $this->algolia,
            'product_detail' => $this->productDetail->payload,
            'advertised_price' => $this->advertisedPrice->payload,
        ];
    }

    public function title(): ?string
    {
        return $this->optionalAlgoliaString('name');
    }

    public function price(): string|int|float|null
    {
        return Arr::get($this->advertisedPrice->payload, 'price', Arr::get($this->algolia, 'pricing.price'));
    }

    public function packageText(): ?string
    {
        return $this->optionalAlgoliaString('underline');
    }

    public function sourceUnitPrice(): string|int|float|null
    {
        return Arr::get($this->advertisedPrice->payload, 'compare_unit_price')
            ?? $this->priceFromUnitText($this->optionalAlgoliaString('pricing.price_per_unit'));
    }

    public function description(): ?string
    {
        return $this->optionalAlgoliaString('description_short') ?? $this->optionalAlgoliaString('description');
    }

    public function imageUrl(): ?string
    {
        foreach (['images.0.large', 'images.0.medium', 'images.0.small'] as $key) {
            $url = $this->optionalAlgoliaString($key) ?? $this->optionalDetailString($key);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function sourceOfferId(): ?string
    {
        return $this->optionalDetailScalarString('id') ?? $this->optionalAlgoliaScalarString('objectID');
    }

    public function sourceProductId(): ?string
    {
        return $this->optionalDetailScalarString('id') ?? $this->optionalAlgoliaScalarString('id');
    }

    public function purchaseLimitText(): ?string
    {
        $maxQuantity = Arr::get($this->advertisedPrice->payload, 'max_quantity', Arr::get($this->algolia, 'pricing.max_quantity'));

        if (! is_numeric($maxQuantity) || (int) $maxQuantity <= 0) {
            return null;
        }

        return 'Maks. '.(int) $maxQuantity;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return array_filter([
            'brand' => $this->optionalAlgoliaString('hf2'),
            'department_id' => Arr::get($this->algolia, 'department_id'),
            'department_name' => $this->optionalAlgoliaString('department_name'),
            'category_id' => Arr::get($this->algolia, 'category_id'),
            'category_name' => $this->optionalAlgoliaString('category_name'),
            'bar_codes' => Arr::get($this->productDetail->payload, 'bar_codes', Arr::get($this->algolia, 'bar_codes')),
            'price_starts_at' => $this->optionalPriceString('starting_at'),
            'price_ends_at' => $this->optionalPriceString('ending_at'),
            'is_campaign' => Arr::get($this->advertisedPrice->payload, 'is_campaign'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function priceFromUnitText(?string $unitPrice): ?string
    {
        if ($unitPrice === null || preg_match('/(?<price>\d+(?:[,.]\d+)?)/', $unitPrice, $matches) !== 1) {
            return null;
        }

        return $matches['price'];
    }

    private function optionalAlgoliaString(string $key): ?string
    {
        return $this->optionalString($this->algolia, $key);
    }

    private function optionalDetailString(string $key): ?string
    {
        return $this->optionalString($this->productDetail->payload, $key);
    }

    private function optionalPriceString(string $key): ?string
    {
        return $this->optionalString($this->advertisedPrice->payload, $key);
    }

    private function optionalAlgoliaScalarString(string $key): ?string
    {
        return $this->optionalScalarString($this->algolia, $key);
    }

    private function optionalDetailScalarString(string $key): ?string
    {
        return $this->optionalScalarString($this->productDetail->payload, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalScalarString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
            return (string) $value;
        }

        return null;
    }
}
