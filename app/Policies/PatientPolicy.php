<?php

namespace App\Policies;

use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAbility('patients:read');
    }
}
