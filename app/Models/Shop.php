<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'shop_domain',
    'access_token',
    'access_token_expires_at',
    'refresh_token',
    'refresh_token_expires_at',
    'scopes',
    'installed_at',
    'uninstalled_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token' => 'encrypted',
            'refresh_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    /**
     * The wholesale tiers this shop has configured.
     *
     * @return HasMany<TierSetting, $this>
     */
    public function tierSettings(): HasMany
    {
        return $this->hasMany(TierSetting::class);
    }
}
