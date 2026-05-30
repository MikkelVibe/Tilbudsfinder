<?php

namespace App\Enums;

enum ScrapeJobStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Running = 'running';
    case Uploading = 'uploading';
    case Succeeded = 'succeeded';
    case NoChanges = 'no_changes';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
