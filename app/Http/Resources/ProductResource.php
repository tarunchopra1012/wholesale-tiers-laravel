<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product, as already flattened by ProductQuery.
 *
 * @property array{id: string, title: string, priceCents: int, currency: string} $resource
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'title' => $this->resource['title'],
            'price_cents' => $this->resource['priceCents'],
            'currency' => $this->resource['currency'],
        ];
    }
}
