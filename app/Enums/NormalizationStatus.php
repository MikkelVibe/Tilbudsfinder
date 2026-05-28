<?php

namespace App\Enums;

enum NormalizationStatus: string
{
    case NotAttempted = 'not_attempted';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
