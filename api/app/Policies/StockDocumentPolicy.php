<?php

namespace App\Policies;

use App\Models\StockDocument;
use App\Models\User;

/**
 * One policy for the four document types; each type has its own permission prefix (damages, supplier-returns,
 * adjustments, stock-counts). Lines are part of the document and use `<prefix>.update`.
 */
class StockDocumentPolicy
{
    public function viewAny(User $user, string $type): bool
    {
        return $this->can($user, $type, 'view');
    }

    public function create(User $user, string $type): bool
    {
        return $this->can($user, $type, 'create');
    }

    public function view(User $user, StockDocument $document): bool
    {
        return $this->can($user, $document->type, 'view');
    }

    public function update(User $user, StockDocument $document): bool
    {
        return $this->can($user, $document->type, 'update');
    }

    public function delete(User $user, StockDocument $document): bool
    {
        return $this->can($user, $document->type, 'delete');
    }

    /** Post a draft: moves the stock. */
    public function post(User $user, StockDocument $document): bool
    {
        return $this->can($user, $document->type, 'post');
    }

    private function can(User $user, string $type, string $action): bool
    {
        return $user->checkPermissionTo(StockDocument::TYPES[$type]['permission'].'.'.$action);
    }
}
