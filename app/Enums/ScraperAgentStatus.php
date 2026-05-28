<?php

namespace App\Enums;

enum ScraperAgentStatus: string
{
    case Active = 'active';
    case Missing = 'missing';
    case Disabled = 'disabled';
}
