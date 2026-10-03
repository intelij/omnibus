<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingPassenger;
use App\Models\Passenger;
use Illuminate\Http\Request;

class PassengerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Booking::class);

        $q = Passenger::query()
            ->when($request->q, fn ($query, $v) => $query->where(fn ($q2) => $q2
                ->where('first_name', 'like', "%{$v}%")
                ->orWhere('last_name', 'like', "%{$v}%")
                ->orWhere('email', 'like', "%{$v}%")
                ->orWhere('phone', 'like', "%{$v}%")
                ->orWhere('id_document', 'like', "%{$v}%")))
            ->orderBy('last_name');

        return response()->json($q->paginate($request->integer('per_page', 20)));
    }
}
