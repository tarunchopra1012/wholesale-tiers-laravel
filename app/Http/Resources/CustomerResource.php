<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One customer, as already flattened by CustomerQuery.
 *
 * @property array{id: string, firstName: ?string, lastName: ?string, email: ?string, tags: list<string>, location: ?string} $resource
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'first_name' => $this->resource['firstName'],
            'last_name' => $this->resource['lastName'],
            'email' => $this->resource['email'],
            'tags' => $this->resource['tags'],
            'location' => $this->resource['location'],
        ];
    }
}
