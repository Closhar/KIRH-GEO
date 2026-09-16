<?php

declare(strict_types=1);

namespace App\Modules\Location\Domain;

enum LocationMode: string
{
    case Idle = 'idle';
    case Normal = 'normal';
    case Live = 'live';
    case Sport = 'sport';
    case Sos = 'sos';
}
