<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Invoice;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): Response
    {
        return $user->hasAbility('invoices:read') && $invoice->visit !== null && $user->canAccessBranch($invoice->visit->branch_id)
            ? Response::allow() : Response::denyAsNotFound();
    }
}
