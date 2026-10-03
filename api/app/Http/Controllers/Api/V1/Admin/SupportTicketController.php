<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SupportTicket::class);

        return response()->json(
            SupportTicket::with(['assignee:id,name', 'booking:id,booking_reference'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->q, fn ($q, $v) => $q->where(fn ($q2) => $q2
                    ->where('subject', 'like', "%{$v}%")->orWhere('customer_name', 'like', "%{$v}%")))
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', SupportTicket::class);

        $data = $request->validate([
            'booking_id' => ['nullable', 'integer', Rule::exists('bookings', 'id')->where('tenant_id', tenant_id())],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_contact' => ['nullable', 'string', 'max:120'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
        ]);

        $ticket = SupportTicket::create($data + ['created_by' => $request->user()->id]);
        Audit::record('support_ticket.created', $ticket);

        return response()->json($ticket, 201);
    }

    public function show(SupportTicket $supportTicket)
    {
        $this->authorize('view', $supportTicket);

        return response()->json($supportTicket->load(['notes.user:id,name', 'assignee:id,name', 'booking:id,booking_reference,status']));
    }

    public function update(Request $request, SupportTicket $supportTicket)
    {
        $this->authorize('update', $supportTicket);

        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
        ]);

        $supportTicket->update($data);
        Audit::record('support_ticket.updated', $supportTicket, $data);

        return response()->json($supportTicket);
    }

    public function destroy(SupportTicket $supportTicket)
    {
        $this->authorize('close', $supportTicket);
        $supportTicket->update(['status' => 'closed']);
        Audit::record('support_ticket.closed', $supportTicket);

        return response()->json(['message' => 'Ticket closed.']);
    }

    public function addNote(Request $request, SupportTicket $supportTicket)
    {
        $this->authorize('update', $supportTicket);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $note = $supportTicket->notes()->create([
            'tenant_id' => $supportTicket->tenant_id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return response()->json($note, 201);
    }

    public function assign(Request $request, SupportTicket $supportTicket)
    {
        $this->authorize('assign', $supportTicket);

        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', tenant_id())],
        ]);

        $supportTicket->update($data);
        Audit::record('support_ticket.assigned', $supportTicket, $data);

        return response()->json($supportTicket);
    }
}
