<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTierSettingRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided which shop is
     * calling, and the tier is created on that shop.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Shop $shop */
        $shop = $this->attributes->get('shop');

        return [
            'tag' => TierRules::tag($shop),
            'discount_type' => TierRules::discountType(),
            'discount_value' => TierRules::discountValue($this->input('discount_type')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TierRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'discount_type' => 'discount type',
            'discount_value' => 'discount',
        ];
    }
}
