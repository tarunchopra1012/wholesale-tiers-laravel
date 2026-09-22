<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TierSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TierSetting $resource
 */
final class TierSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tag' => $this->resource->tag,
            'discount_type' => $this->resource->discount_type->value,
            // A string such as "25.00", straight from the decimal:2 cast, so
            // the browser gets exactly what's stored.
            'discount_value' => $this->resource->discount_value,
        ];
    }
}
