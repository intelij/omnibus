<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::with('booking:id,booking_reference')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->date_from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return PaymentResource::collection($payments);
    }

    public function show(Payment $payment)
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load(['booking', 'transactions', 'refunds']));
    }

    /** Finance/admin cash confirmation. */
    public function confirm(Payment $payment, Request $request)
    {
        $this->authorize('confirmCash', $payment);

        return new PaymentResource($this->payments->confirmCash($payment, $request->user()));
    }

    public function refund(Payment $payment, Request $request)
    {
        $this->authorize('refund', $payment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $refund = $this->payments->refund($payment, (float) $data['amount'], $data['reason'], $request->user());

        return response()->json($refund, 201);
    }

    public function reconciliation(Request $request)
    {
        $this->authorize('reconcile', Payment::class);

        return response()->json([
            'pending' => Payment::where('status', 'pending')->with('booking:id,booking_reference')->orderBy('created_at')->get(),
            'processing' => Payment::where('status', 'processing')->with('booking:id,booking_reference')->orderBy('created_at')->get(),
            'failed_today' => Payment::where('status', 'failed')->whereDate('created_at', today())->count(),
        ]);
    }

    public function export(Request $request)
    {
        $this->authorize('export', Payment::class);

        $rows = Payment::with('booking:id,booking_reference')
            ->when($request->date_from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderBy('created_at')
            ->get(['id', 'booking_id', 'method', 'amount', 'currency', 'status', 'provider_ref', 'confirmed_at', 'created_at']);

        $csv = "id,booking,method,amount,currency,status,provider_ref,confirmed_at,created_at\n";
        foreach ($rows as $p) {
            $csv .= implode(',', [
                $p->id, $p->booking?->booking_reference, $p->method, $p->amount,
                $p->currency, $p->status, $p->provider_ref, $p->confirmed_at, $p->created_at,
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="payments-export.csv"',
        ]);
    }
}
