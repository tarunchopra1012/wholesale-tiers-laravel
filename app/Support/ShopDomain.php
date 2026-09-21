<?php

declare(strict_types=1);

namespace App\Support;

use Stringable;

/**
 * A shop's permanent *.myshopify.com domain, with its format already checked.
 *
 * The only way to get one is tryFrom(), so any code holding a ShopDomain can
 * put it in a URL without checking again. It checks format only: it cannot
 * tell whether the shop exists or has installed the app.
 */
final readonly class ShopDomain implements Stringable
{
    /**
     * Shopify's documented pattern, anchored at both ends. Without the end
     * anchor, "shop.myshopify.com.attacker.example" would pass.
     *
     * \z rather than $: in PHP, $ also matches just before a trailing
     * newline, so "shop.myshopify.com\n" would pass with $.
     */
    private const PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com\z/';

    private function __construct(public string $value) {}

    /**
     * Takes mixed because it sits on untrusted input: a query string can
     * carry an array (?shop[]=x) as easily as a string.
     *
     * Lowercases, so one shop can't become two rows by casing. Does not
     * trim: Shopify never sends whitespace, and trimming would quietly
     * accept the trailing-newline input the pattern exists to reject.
     */
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower($value);

        return preg_match(self::PATTERN, $value) === 1 ? new self($value) : null;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
