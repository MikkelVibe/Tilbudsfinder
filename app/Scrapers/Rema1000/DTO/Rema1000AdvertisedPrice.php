<?php

namespace App\Scrapers\Rema1000\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

readonly class Rema1000AdvertisedPrice
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function startsAt(): ?CarbonImmutable
    {
        return $this->date('starting_at');
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->date('ending_at');
    }

    public function isAdvertised(): bool
    {
        return Arr::get($this->payload, 'is_advertised') === true;
    }

    public function isUsable(): bool
    {
        return $this->isAdvertised() && $this->startsAt() !== null && $this->endsAt() !== null;
    }

    public function overlapSeconds(Rema1000Catalog $catalog): int
    {
        $startsAt = $this->startsAt();
        $endsAt = $this->endsAt();
        $catalogStart = $catalog->activeFrom();
        $catalogEnd = $catalog->activeUntil();

        if ($startsAt === null || $endsAt === null || $catalogStart === null || $catalogEnd === null) {
            return 0;
        }

        $overlapStart = $startsAt->greaterThan($catalogStart) ? $startsAt : $catalogStart;
        $overlapEnd = $endsAt->lessThan($catalogEnd) ? $endsAt : $catalogEnd;

        return $overlapStart->lessThan($overlapEnd)
            ? $overlapEnd->getTimestamp() - $overlapStart->getTimestamp()
            : 0;
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = Arr::get($this->payload, $key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
