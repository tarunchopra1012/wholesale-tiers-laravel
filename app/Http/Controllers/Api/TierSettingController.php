<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTierSettingRequest;
use App\Http\Requests\UpdateTierSettingsRequest;
use App\Http\Resources\TierSettingResource;
use App\Models\Shop;
use App\Services\Shopify\OAuthService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\TierDiscountSync;
use App\Support\TierDiscountConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * POST /api/tiers — add a tier. Answers 201 with the new tier.
     *
     * @status 201
     */
    public function store(StoreTierSettingRequest $request, OAuthService $oauth): TierSettingResource
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        try {
            $tier = $shop->tierSettings()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            // Validation already checked the tag, but two requests can both
            // pass that check before either inserts. The unique index is
            // the real guard; this turns it into an answer the page can show.
            throw ValidationException::withMessages(['tag' => 'Another tier already uses this tag.']);
        }

        $this->syncCheckout($shop, $oauth);

        // A resource for a model created in this request answers 201.
        return new TierSettingResource($tier);
    }

    /**
     * PUT /api/tiers — change the tag, name or discount of some or all of the
     * shop's tiers, matched by id. Answers with every tier, as GET does.
     */
    public function update(UpdateTierSettingsRequest $request, OAuthService $oauth): AnonymousResourceCollection
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        /** @var list<array{id: int, tag: string, name?: ?string, discount_type: string, discount_value: int|float|string, badge_tone: string}> $changes */
        $changes = $request->validated('tiers');

        try {
            // All or nothing: a failure halfway must not leave gold saved and
            // silver not.
            DB::transaction(function () use ($shop, $changes): void {
                $tiers = $shop->tierSettings()
                    ->whereIn('id', array_column($changes, 'id'))
                    ->get()
                    ->keyBy('id');

                foreach ($changes as $change) {
                    $tiers[$change['id']]->update([
                        'tag' => $change['tag'],
                        // Left out or emptied both mean "show the tag".
                        'name' => $change['name'] ?? null,
                        'discount_type' => $change['discount_type'],
                        'discount_value' => $change['discount_value'],
                        'badge_tone' => $change['badge_tone'],
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // As in store(): another request took a tag between validation
            // and the update. The transaction has rolled everything back.
            throw ValidationException::withMessages([
                'tiers' => 'Another tier took one of these tags while you were editing. Reload the page and try again.',
            ]);
        }

        $this->syncCheckout($shop, $oauth);

        return TierSettingResource::collection($shop->tierSettings()->orderBy('tag')->get());
    }

    /**
     * DELETE /api/tiers/{id} — remove a tier. Customers keep their tags in
     * Shopify; the app just stops treating that tag as a tier.
     */
    public function destroy(Request $request, OAuthService $oauth, int $id): Response
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        // Looked up through the shop rather than bound from the route:
        // route-model binding would find any shop's tier by its id.
        $tier = $shop->tierSettings()->find($id) ?? abort(404, 'This store has no tier with that ID.');

        $tier->delete();

        $this->syncCheckout($shop, $oauth);

        return response()->noContent();
    }

    /**
     * Sends the shop's tiers, as they now are, to the checkout Function.
     * After the save, never inside its transaction: a slow Shopify must not
     * hold database locks, and a failed sync must not undo the save.
     */
    private function syncCheckout(Shop $shop, OAuthService $oauth): void
    {
        (new TierDiscountSync(new ShopifyGraphQLClient($shop, $oauth), new TierDiscountConfig))->sync($shop);
    }
}
