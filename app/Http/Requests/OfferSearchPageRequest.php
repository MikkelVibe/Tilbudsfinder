<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfferSearchPageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $grocers = $this->query('grocers');

        if (is_string($grocers)) {
            $this->merge([
                'grocers' => collect(explode(',', $grocers))
                    ->map(static fn (string $slug): string => trim($slug))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }
    }

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
            'grocers' => ['nullable', 'array'],
            'grocers.*' => ['string', 'max:120'],
            'sort' => ['nullable', Rule::in(['relevance', 'price_asc', 'price_desc', 'unit_price_asc', 'name_asc', 'name_desc'])],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<string>
     */
    public function grocerSlugs(): array
    {
        return collect($this->validated('grocers', []))
            ->filter(static fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->map(static fn (string $slug): string => trim($slug))
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

    public function priceMin(): ?float
    {
        $price = $this->validated('price_min');

        return is_numeric($price) ? (float) $price : null;
    }

    public function priceMax(): ?float
    {
        $price = $this->validated('price_max');

        return is_numeric($price) ? (float) $price : null;
    }
}
