<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The colour of a tier's badge on the Customers page. The values are the
 * tones of Polaris's Badge, so the browser passes one straight to it.
 */
enum BadgeTone: string
{
    case Blue = 'info';
    case Green = 'success';
    case Yellow = 'attention';
    case Orange = 'warning';
    case Red = 'critical';
    case Purple = 'magic';
    case Grey = 'new';
}
