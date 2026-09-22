<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided who is calling, and
     * the access token only reaches that shop's products.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Shopify's global ID for a product. It goes to Shopify as a
            // typed ID variable, not into a search string, so this isn't
            // about injection: a malformed ID gets a clear 422 here instead
            // of a trip to Shopify. \z rather than $, as in ShopDomain.
            'product_id' => ['required', 'string', 'regex:/^gid:\/\/shopify\/Product\/\d+\z/'],
        ];
    }
}
