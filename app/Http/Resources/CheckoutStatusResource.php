<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CheckoutState;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{state: CheckoutState, syncedAt: ?CarbonInterface} $resource
 */
final class CheckoutStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'state' => $this->resource['state']->value,
            // When the tiers last reached Shopify, in UTC; the browser shows
            // it in the merchant's own time zone. Null before the first sync.
            'synced_at' => $this->resource['syncedAt']?->toIso8601String(),
        ];
    }
}
