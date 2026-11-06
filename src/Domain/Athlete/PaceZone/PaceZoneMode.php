<?php

declare(strict_types=1);

namespace App\Domain\Athlete\PaceZone;

enum PaceZoneMode: string
{
    case DEFAULT = 'default';
    case THRESHOLD = 'threshold';
    case CUSTOM = 'custom';
}
