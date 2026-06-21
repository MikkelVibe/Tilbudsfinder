<?php

namespace App\Http\Requests\Api\V1;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'grocers' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', Rule::in(['relevance', 'price_asc', 'price_desc', 'unit_price_asc', 'name_asc', 'name_desc'])],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return list<string>
     */
    public function grocerSlugs(): array
    {
        $grocers = $this->validated('grocers');

        if (! is_string($grocers) || trim($grocers) === '') {
            return [];
        }

        return collect(explode(',', $grocers))
            ->map(static fn (string $slug): string => trim($slug))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function queryText(): ?string
    {
        $query = $this->validated('q');

        if (! is_string($query)) {
            return null;
        }

        $query = trim($query);

        return $query === '' ? null : $query;
    }

    public function sort(): string
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? $sort : 'relevance';
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : 24;
    }

    public function priceMin(): ?BigDecimal
    {
        $price = $this->validated('price_min');

        return is_numeric($price) ? BigDecimal::of((string) $price) : null;
    }

    public function priceMax(): ?BigDecimal
    {
        $price = $this->validated('price_max');

        return is_numeric($price) ? BigDecimal::of((string) $price) : null;
    }
}
