import {
  DiscountClass,
  ProductDiscountSelectionStrategy,
} from '../generated/api';

/**
  * @typedef {import("../generated/api").CartInput} RunInput
  * @typedef {import("../generated/api").CartLinesDiscountsGenerateRunResult} CartLinesDiscountsGenerateRunResult
  * @typedef {{tag: string, name?: string, type: 'percentage' | 'fixed', value: string}} Tier
  */

/**
  * The tiers come from the discount's metafield, which Laravel writes on
  * every save in the app's Settings page: {"tags": [...], "tiers": [...]}.
  *
  * @param {RunInput} input
  * @returns {CartLinesDiscountsGenerateRunResult}
  */
export function cartLinesDiscountsGenerateRun(input) {
  /** @type {Tier[]} */
  const tiers = input.discount.metafield?.jsonValue?.tiers ?? [];

  // A guest has no customer at all, so they end up with no tags here, like
  // a customer who has none of the tiers' tags.
  const customerTags = (input.cart.buyerIdentity?.customer?.hasTags ?? [])
    .filter((response) => response.hasTag)
    .map((response) => response.tag.toLowerCase());

  // Shopify matches tags without regard to case, so this does too.
  const customerTiers = tiers.filter((tier) =>
    customerTags.includes(tier.tag.toLowerCase()),
  );

  const hasProductDiscountClass = input.discount.discountClasses.includes(
    DiscountClass.Product,
  );

  if (!customerTiers.length || !hasProductDiscountClass) {
    return {operations: []};
  }

  // A customer in two tiers gets, on each line, the tier that takes the
  // most off. That can differ by line: 10% beats 5.00 off on a 100.00
  // product and loses to it on a 20.00 one.
  /** @type {Map<Tier, string[]>} */
  const lineIdsByTier = new Map();

  for (const line of input.cart.lines) {
    const unitPrice = Number(line.cost.amountPerQuantity.amount);
    const best = customerTiers.reduce((winner, tier) =>
      amountOff(tier, unitPrice) > amountOff(winner, unitPrice) ? tier : winner,
    );

    lineIdsByTier.set(best, [...(lineIdsByTier.get(best) ?? []), line.id]);
  }

  if (!lineIdsByTier.size) {
    return {operations: []};
  }

  return {
    operations: [
      {
        productDiscountsAdd: {
          candidates: [...lineIdsByTier].map(([tier, lineIds]) => ({
            // A metafield written before tiers had names has no name.
            message: tier.name ?? tier.tag,
            targets: lineIds.map((id) => ({cartLine: {id}})),
            value:
              tier.type === 'fixed'
                ? // Off each unit, as the app's price preview calculates it.
                  {fixedAmount: {amount: tier.value, appliesToEachItem: true}}
                : {percentage: {value: tier.value}},
          })),
          // No line is in two candidates, so there is nothing to choose.
          selectionStrategy: ProductDiscountSelectionStrategy.All,
        },
      },
    ],
  };
}

/**
  * What a tier takes off one unit. Only used to compare tiers: Shopify
  * works out the real discount from the candidate's value.
  *
  * @param {Tier} tier
  * @param {number} unitPrice
  */
function amountOff(tier, unitPrice) {
  const value = Number(tier.value);

  return tier.type === 'fixed'
    ? Math.min(value, unitPrice)
    : (unitPrice * value) / 100;
}
