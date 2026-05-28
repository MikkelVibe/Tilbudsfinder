<?php

namespace App\Normalization\Enums;

enum NormalizedOfferStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Rejected = 'rejected';
}
