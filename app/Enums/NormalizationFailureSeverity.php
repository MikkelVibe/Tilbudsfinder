<?php

namespace App\Enums;

enum NormalizationFailureSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
