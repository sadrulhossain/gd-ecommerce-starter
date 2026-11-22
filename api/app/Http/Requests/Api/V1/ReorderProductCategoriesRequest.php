<?php

namespace App\Http\Requests\Api\V1;

class ReorderProductCategoriesRequest extends ReorderRequest
{
    protected function table(): string
    {
        return 'product_categories';
    }

    protected function permission(): string
    {
        return 'product-categories.update';
    }
}
