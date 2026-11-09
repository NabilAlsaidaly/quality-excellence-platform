<?php

namespace App\Actions\Approaches;

use App\Models\Approach;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Illuminate\Auth\Access\AuthorizationException;

final class CreateAuthorizedApproach
{
    public function __construct(private CreateApproach $create, private ManageApproachAssignments $assignments) {}

    public function execute(User $actor, array $data): Approach
    {
        return $this->create->withinContext($actor, function () use ($actor, $data): Approach {
            if (! app(ApproachPolicy::class)->allowsOperation($actor, 'create')) {
                throw new AuthorizationException;
            }
            $resource = $this->create->execute($actor, $data);
            $this->assignments->initialRecipients($actor, $resource);

            return $resource;
        });
    }
}
