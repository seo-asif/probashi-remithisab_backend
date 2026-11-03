<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\PlanPaymentRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'approved', 'rejected'])],
            'product' => ['nullable', Rule::in(['all', ...array_column(PlanPaymentProduct::cases(), 'value')])],
        ]);
        $search = trim($input['q'] ?? '');
        $status = $input['status'] ?? 'all';
        $product = $input['product'] ?? 'all';
        $query = PlanPaymentRequest::query()->with(['business:id,name', 'submittedBy:id,name,email']);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($product !== 'all') {
            $query->where('product_type', $product);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', $search)
                  ->orWhereHas('business', fn ($b) => $b->where('name', 'like', '%'.$search.'%'))
                  ->orWhereHas('submittedBy', fn ($u) => $u->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }
        $payments = $query->orderByDesc('id')->paginate(15)->withQueryString();
        return view('admin.payments', compact('payments', 'search', 'status', 'product'));
    }
}