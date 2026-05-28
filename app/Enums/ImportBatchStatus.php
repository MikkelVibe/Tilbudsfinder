<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Parsing = 'parsing';
    case Validating = 'validating';
    case Persisting = 'persisting';
    case Activating = 'activating';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case Quarantined = 'quarantined';
}
