<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const TENANT_ROLES = ['tenant_owner', 'tenant_admin', 'booking_agent', 'finance_officer', 'support_agent'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        return response()->json(
            User::where('tenant_id', tenant_id())
                ->with('roles:id,name')
                ->when($request->q, fn ($q, $v) => $q->where(fn ($q2) => $q2
                    ->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', Rule::in(self::TENANT_ROLES)],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'tenant_id' => tenant_id(),
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => 'active',
            ]);
            $user->tenants()->syncWithoutDetaching([tenant_id() => ['invited_by' => $request->user()->id]]);
            $user->assignRole(Role::findByName($data['role'], 'sanctum'));

            return $user;
        });

        Audit::record('user.created', $user, ['role' => $data['role']]);

        return response()->json($user->load('roles:id,name'), 201);
    }

    public function show(User $user)
    {
        $this->authorize('viewAny', User::class);
        abort_unless($user->tenant_id === tenant_id(), 404);

        return response()->json($user->load('roles:id,name'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        abort_unless($user->tenant_id === tenant_id(), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(self::TENANT_ROLES)],
            'password' => ['nullable', 'string', 'min:10'],
        ]);

        $user->update(collect($data)->only(['name', 'phone', 'password'])->filter()->all());

        if (isset($data['role'])) {
            $user->syncRoles([Role::findByName($data['role'], 'sanctum')]);
            Audit::record('user.role_changed', $user, ['role' => $data['role']]);
        }

        Audit::record('user.updated', $user);

        return response()->json($user->load('roles:id,name'));
    }

    public function destroy(User $user)
    {
        $this->authorize('disable', $user);
        abort_unless($user->tenant_id === tenant_id(), 404);

        $user->update(['status' => 'disabled']);
        $user->tokens()->delete();
        Audit::record('user.disabled', $user);

        return response()->json(['message' => 'User disabled.']);
    }

    public function setStatus(Request $request, User $user)
    {
        $this->authorize('disable', $user);
        abort_unless($user->tenant_id === tenant_id(), 404);

        $data = $request->validate(['status' => ['required', 'in:active,disabled']]);
        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'disabled') {
            $user->tokens()->delete();
        }

        Audit::record('user.status_changed', $user, ['status' => $data['status']]);

        return response()->json($user);
    }
}
