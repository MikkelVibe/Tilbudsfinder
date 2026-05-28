<?php

namespace App\Enums;

enum GrocerHealthStatus: string
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case Failing = 'failing';
    case Disabled = 'disabled';
}
