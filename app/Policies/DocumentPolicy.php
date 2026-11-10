<?php

namespace App\Policies;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\User;

/** No grants outside the independently verified named TEST_ONLY request/transaction. */
final class DocumentPolicy
{
    public function __construct(private DocumentWorkspaceAccess $access) {}

    public function workspaceOperation(User $actor, string $operation, ?Document $document = null): bool
    {
        return $this->access->allows($actor, $operation, $document);
    }

    public function viewAny(User $actor): bool
    {
        return $this->workspaceOperation($actor, 'list');
    }

    public function create(User $actor): bool
    {
        return $this->workspaceOperation($actor, 'create');
    }

    public function view(User $actor, Document $document): bool
    {
        return $this->workspaceOperation($actor, 'view', $document);
    }

    public function update(User $actor, Document $document): bool
    {
        return $this->workspaceOperation($actor, 'metadata', $document);
    }
}
