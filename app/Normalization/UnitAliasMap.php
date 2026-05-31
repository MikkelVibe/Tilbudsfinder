<?php

namespace App\Normalization;

use App\Normalization\Enums\PackageUnit;

class UnitAliasMap
{
    /**
     * @var array<string, PackageUnit>
     */
    private const ALIASES = [
        'g' => PackageUnit::Gram,
        'gr' => PackageUnit::Gram,
        'gr.' => PackageUnit::Gram,
        'gram' => PackageUnit::Gram,
        'kg' => PackageUnit::Kilogram,
        'kg.' => PackageUnit::Kilogram,
        'kilo' => PackageUnit::Kilogram,
        'kilogram' => PackageUnit::Kilogram,
        'ml' => PackageUnit::Milliliter,
        'ml.' => PackageUnit::Milliliter,
        'milliliter' => PackageUnit::Milliliter,
        'cl' => PackageUnit::Centiliter,
        'cl.' => PackageUnit::Centiliter,
        'centiliter' => PackageUnit::Centiliter,
        'l' => PackageUnit::Liter,
        'l.' => PackageUnit::Liter,
        'ltr' => PackageUnit::Liter,
        'ltr.' => PackageUnit::Liter,
        'liter' => PackageUnit::Liter,
        'litr' => PackageUnit::Liter,
        'lt' => PackageUnit::Liter,
        'stk' => PackageUnit::Piece,
        'stk.' => PackageUnit::Piece,
        'st' => PackageUnit::Piece,
        'styk' => PackageUnit::Piece,
        'stykker' => PackageUnit::Piece,
        'pcs' => PackageUnit::Piece,
        'piece' => PackageUnit::Piece,
        'pieces' => PackageUnit::Piece,
        'pk' => PackageUnit::Package,
        'pk.' => PackageUnit::Package,
        'pakke' => PackageUnit::Package,
        'pakker' => PackageUnit::Package,
        'bk' => PackageUnit::Tray,
        'bk.' => PackageUnit::Tray,
        'bakke' => PackageUnit::Tray,
        'bakker' => PackageUnit::Tray,
        'sæt' => PackageUnit::Set,
        'saet' => PackageUnit::Set,
        'set' => PackageUnit::Set,
        'par' => PackageUnit::Pair,
        'm' => PackageUnit::Meter,
        'm.' => PackageUnit::Meter,
        'meter' => PackageUnit::Meter,
        'metre' => PackageUnit::Meter,
    ];

    public function normalize(string $unit): ?PackageUnit
    {
        $normalized = mb_strtolower(trim($unit));
        $normalized = rtrim($normalized, ',;:');

        return self::ALIASES[$normalized] ?? null;
    }

    public function unitPattern(): string
    {
        $aliases = array_map(
            static fn (string $alias): string => preg_quote($alias, '/'),
            array_keys(self::ALIASES),
        );

        usort($aliases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', $aliases);
    }
}
