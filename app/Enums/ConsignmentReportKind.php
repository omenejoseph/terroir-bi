<?php

declare(strict_types=1);

namespace App\Enums;

enum ConsignmentReportKind: string
{
    case Sale = 'SALE';
    case Return = 'RETURN';
}
