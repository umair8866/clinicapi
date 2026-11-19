<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;
use App\Models\Branch;
use Illuminate\Auth\Access\Response;

class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAbility('visits:read');
    }
    public function view(User $user, Visit $visit): Response
    {
        return $user->hasAbility('visits:read') && $user->canAccessBranch($visit->branch_id)
            ? Response::allow() : Response::denyAsNotFound();
    }
    public function create(User $user, Branch $branch): bool
    {
        return $user->hasAbility('visits:write') && $branch->is_active && $user->canAccessBranch($branch->id);
    }
}
