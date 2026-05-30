<?php

namespace App\Scrapers\Rema1000\DTO;

use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

readonly class Rema1000Catalog
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function id(): ?string
    {
        return $this->optionalString('id');
    }

    public function requiredId(): string
    {
        $id = $this->id();

        if ($id === null) {
            throw new ScraperParseException('REMA 1000 payload is missing catalog id.');
        }

        return $id;
    }

    public function label(): ?string
    {
        return $this->optionalString('label');
    }

    public function activeFrom(): ?CarbonImmutable
    {
        return $this->date('run_from');
    }

    public function activeUntil(): ?CarbonImmutable
    {
        return $this->date('run_till');
    }

    public function offerCount(): ?int
    {
        $offerCount = Arr::get($this->payload, 'offer_count');

        return is_numeric($offerCount) ? (int) $offerCount : null;
    }

    public function isActiveAt(CarbonImmutable $date): bool
    {
        $activeFrom = $this->activeFrom();
        $activeUntil = $this->activeUntil();

        return $activeFrom !== null
            && $activeUntil !== null
            && $activeFrom->lessThanOrEqualTo($date)
            && $activeUntil->greaterThanOrEqualTo($date);
    }

    public function isWeekly(): bool
    {
        $label = $this->label();

        return $label !== null && Str::contains($label, 'Uge', ignoreCase: true);
    }

    public function isEligibleWeeklyPaper(CarbonImmutable $date, int $minimumOfferCount = 10): bool
    {
        return $this->isWeekly()
            && ($this->offerCount() ?? 0) >= $minimumOfferCount
            && $this->isActiveAt($date);
    }

    public function durationInSeconds(): int
    {
        $activeFrom = $this->activeFrom();
        $activeUntil = $this->activeUntil();

        if ($activeFrom === null || $activeUntil === null) {
            return PHP_INT_MAX;
        }

        return $activeUntil->getTimestamp() - $activeFrom->getTimestamp();
    }

    private function optionalString(string $key): ?string
    {
        $value = Arr::get($this->payload, $key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->optionalString($key);

        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
