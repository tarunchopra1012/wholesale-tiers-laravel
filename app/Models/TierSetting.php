<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeTone;
use App\Enums\DiscountType;
use Database\Factories\TierSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_id', 'tag', 'discount_type', 'discount_value', 'badge_tone'])]
class TierSetting extends Model
{
    /** @use HasFactory<TierSettingFactory> */
    use HasFactory;

    /**
     * The column's default, repeated here so a tier that was just created
     * has its tone without being read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'badge_tone' => BadgeTone::Blue->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'badge_tone' => BadgeTone::class,
        ];
    }

    /**
     * The shop this tier belongs to.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
