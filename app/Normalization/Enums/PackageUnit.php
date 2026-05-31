<?php

namespace App\Normalization\Enums;

enum PackageUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Centiliter = 'cl';
    case Liter = 'l';
    case Piece = 'stk';
    case Meter = 'm';
    case Package = 'pakke';
    case Tray = 'bakke';
    case Set = 'saet';
    case Pair = 'par';

    public function compareUnit(): CompareUnit
    {
        return match ($this) {
            self::Gram, self::Kilogram => CompareUnit::Kilogram,
            self::Milliliter, self::Centiliter, self::Liter => CompareUnit::Liter,
            self::Piece, self::Package, self::Tray, self::Set, self::Pair => CompareUnit::Piece,
            self::Meter => CompareUnit::Meter,
        };
    }
}
