<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Brand;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => app(MediaService::class)->describe($this->image),
            'is_active' => $this->is_active,
        ];
    }
}
