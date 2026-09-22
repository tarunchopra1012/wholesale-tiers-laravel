<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProductIndexRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided who is calling, and
     * a shop may list its own products.
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
            // The Preview page only needs a short list to pick from. 20
            // products cost 23 of the 2000-point budget on the dev store.
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
