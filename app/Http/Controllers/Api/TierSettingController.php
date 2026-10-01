<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTierSettingRequest;
use App\Http\Requests\UpdateTierSettingsRequest;
use App\Http\Resources\TierSettingResource;
use App\Models\Shop;
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

        dump([ // DEBUG(dump)
            'step' => '[tiers] TierSettingController::index: listing tiers',
            'shop' => $shop->shop_domain,
            'tiers' => $shop->tierSettings()->orderBy('tag')->get()->map->only(['id', 'tag', 'discount_type', 'discount_value'])->all(),
        ]);

        return TierSettingResource::collection($shop->tierSettings()->orderBy('tag')->get());
    }

    /**
     * POST /api/tiers — add a tier. Answers 201 with the new tier.
     *
     * @status 201
     */
    public function store(StoreTierSettingRequest $request): TierSettingResource
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        dump([ // DEBUG(dump)
            'step' => '[tiers] TierSettingController::store: validated, creating',
            'shop' => $shop->shop_domain,
            'validated' => $request->validated(),
        ]);

        try {
            $tier = $shop->tierSettings()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            dump(['step' => '[tiers] store: unique index caught a duplicate tag (race with another request)']); // DEBUG(dump)

            // Validation already checked the tag, but two requests can both
            // pass that check before either inserts. The unique index is
            // the real guard; this turns it into an answer the page can show.
            throw ValidationException::withMessages(['tag' => 'Another tier already uses this tag.']);
        }

        dump(['step' => '[tiers] store: created', 'tier' => $tier->toArray()]); // DEBUG(dump)

        // A resource for a model created in this request answers 201.
        return new TierSettingResource($tier);
    }

    /**
     * PUT /api/tiers — change the tag or discount of some or all of the
     * shop's tiers, matched by id. Answers with every tier, as GET does.
     */
    public function update(UpdateTierSettingsRequest $request): AnonymousResourceCollection
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        /** @var list<array{id: int, tag: string, discount_type: string, discount_value: int|float|string}> $changes */
        $changes = $request->validated('tiers');

        try {
            // All or nothing: a failure halfway must not leave gold saved and
            // silver not.
            DB::transaction(function () use ($shop, $changes): void {
                $tiers = $shop->tierSettings()
                    ->whereIn('id', array_column($changes, 'id'))
                    ->get()
                    ->keyBy('id');

                dump([ // DEBUG(dump)
                    'step' => '[tiers] TierSettingController::update: inside the transaction',
                    'shop' => $shop->shop_domain,
                    'before' => $tiers->map->only(['id', 'tag', 'discount_type', 'discount_value'])->all(),
                    'changes (validated)' => $changes,
                ]);

                foreach ($changes as $change) {
                    $tiers[$change['id']]->update([
                        'tag' => $change['tag'],
                        'discount_type' => $change['discount_type'],
                        'discount_value' => $change['discount_value'],
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            dump(['step' => '[tiers] update: unique index caught a duplicate tag, transaction rolled back']); // DEBUG(dump)
            // As in store(): another request took a tag between validation
            // and the update. The transaction has rolled everything back.
            throw ValidationException::withMessages([
                'tiers' => 'Another tier took one of these tags while you were editing. Reload the page and try again.',
            ]);
        }

        return TierSettingResource::collection($shop->tierSettings()->orderBy('tag')->get());
    }

    /**
     * DELETE /api/tiers/{id} — remove a tier. Customers keep their tags in
     * Shopify; the app just stops treating that tag as a tier.
     */
    public function destroy(Request $request, int $id): Response
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        // Looked up through the shop rather than bound from the route:
        // route-model binding would find any shop's tier by its id.
        dump([ // DEBUG(dump)
            'step' => '[tiers] TierSettingController::destroy: looking up the tier through the shop',
            'shop' => $shop->shop_domain,
            'id' => $id,
            'found' => $shop->tierSettings()->find($id)?->only(['id', 'tag', 'discount_type', 'discount_value']),
        ]);

        $tier = $shop->tierSettings()->find($id) ?? abort(404, 'This store has no tier with that ID.');

        $tier->delete();

        return response()->noContent();
    }
}
