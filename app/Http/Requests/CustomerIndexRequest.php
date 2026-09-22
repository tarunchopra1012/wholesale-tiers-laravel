<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerIndexRequest extends FormRequest
{
    /**
     * The session-token middleware has already decided who is calling, and
     * a shop may list all of its own customers.
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
            // Pasted into Shopify's search syntax, so tag-safe characters
            // only: unchecked, "x OR tag:y" would change what the search
            // matches. It can't reach another shop — the token pins that —
            // but it's still user input going into a query language.
            // The same pattern a tier's tag must match when it's created or
            // renamed, so every tier can be filtered on.
            'tier' => ['nullable', 'string', 'max:255', 'regex:'.TierRules::TAG_PATTERN],
            'after' => ['nullable', 'string', 'max:512'],
        ];
    }
}
