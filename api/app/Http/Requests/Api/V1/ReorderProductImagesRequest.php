<?php

namespace App\Http\Requests\Api\V1;

class ReorderProductImagesRequest extends ReorderRequest
{
    protected function table(): string
    {
        return 'product_images';
    }

    protected function permission(): string
    {
        return 'products.update';
    }
}
