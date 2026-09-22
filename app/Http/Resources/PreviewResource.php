<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TierSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product's base price, and what each tier pays for it.
 *
 * @property array{
 *     product: array{id: string, title: string, priceCents: int, currency: string},
 *     tiers: list<array{tier: TierSetting, finalPriceCents: int}>,
 * } $resource
 */
final class PreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product' => new ProductResource($this->resource['product']),
            // Each tier as GET /api/tiers shows it, plus its price.
            'tiers' => array_map(
                fn (array $row): array => [
                    ...(new TierSettingResource($row['tier']))->resolve($request),
                    'final_price_cents' => $row['finalPriceCents'],
                ],
                $this->resource['tiers'],
            ),
        ];
    }
}
