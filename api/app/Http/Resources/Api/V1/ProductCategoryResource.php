<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductCategory;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductCategory */
class ProductCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => app(MediaService::class)->describe($this->image),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
