<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What has become of the shop's tier discount in Shopify, which is where
 * checkout reads the tiers from.
 */
enum CheckoutState: string
{
    /** The discount exists and Shopify is applying it. */
    case Active = 'active';

    /** It exists but isn't applied: switched off, or not started yet. */
    case Inactive = 'inactive';

    /** It was created once and has since been deleted in the admin. */
    case Missing = 'missing';

    /** No sync has ever created it. */
    case NeverSynced = 'never_synced';
}
