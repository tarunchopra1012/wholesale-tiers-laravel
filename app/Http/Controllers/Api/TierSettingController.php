<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTierSettingsRequest;
use App\Http\Resources\TierSettingResource;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class TierSettingController extends Controller
{
    /**
     * GET /api/tiers — the calling shop's tiers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Set by VerifyShopifySessionToken from the ID token.
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return TierSettingResource::collection($shop->tierSettings()->orderBy('tag')->get());
    }

    /**
     * PUT /api/tiers — change the discount on some or all of the shop's
     * tiers, matched by tag. Answers with every tier, as GET does.
     */
    public function update(UpdateTierSettingsRequest $request): AnonymousResourceCollection
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        /** @var list<array{tag: string, discount_type: string, discount_value: int|float|string}> $changes */
        $changes = $request->validated('tiers');

        // All or nothing: a failure halfway must not leave gold saved and
        // silver not.
        DB::transaction(function () use ($shop, $changes): void {
            $tiers = $shop->tierSettings()
                ->whereIn('tag', array_column($changes, 'tag'))
                ->get()
                ->keyBy('tag');

            foreach ($changes as $change) {
                $tiers[$change['tag']]->update([
                    'discount_type' => $change['discount_type'],
                    'discount_value' => $change['discount_value'],
                ]);
            }
        });

        return TierSettingResource::collection($shop->tierSettings()->orderBy('tag')->get());
    }
}
