<?php

namespace App\Normalization\Enums;

enum NormalizationIssueCode: string
{
    case PriceMissing = 'price_missing';
    case PriceInvalid = 'price_invalid';
    case UnitUnknown = 'unit_unknown';
    case AmountMissing = 'amount_missing';
    case AmountInvalid = 'amount_invalid';
    case AmountRange = 'amount_range';
    case UnitPriceMismatch = 'unit_price_mismatch';
    case ConditionalOffer = 'conditional_offer';
}
