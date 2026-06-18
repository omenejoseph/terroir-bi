<?php

declare(strict_types=1);

namespace App\Enums;

/** The kind of wine movement a cellar transfer records. */
enum CellarTransferType: string
{
    case Rack = 'RACK';   // move within the same lot, vessel → vessel
    case Blend = 'BLEND'; // move volume from one lot into another
    case Split = 'SPLIT'; // break a lot into a separate lot
}
