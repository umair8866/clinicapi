<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);
        return new InvoiceResource($invoice->load(['visit:id,branch_id', 'items:id,invoice_id,service_code,qty,unit_price_minor', 'payments:id,invoice_id,method,amount_minor,paid_at']));
    }
}
