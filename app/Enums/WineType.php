<?php

declare(strict_types=1);

namespace App\Enums;

/** The style of wine in a lot. */
enum WineType: string
{
    case Red = 'RED';
    case White = 'WHITE';
    case Rose = 'ROSE';
    case Orange = 'ORANGE';
    case Sparkling = 'SPARKLING';
    case Dessert = 'DESSERT';
}
