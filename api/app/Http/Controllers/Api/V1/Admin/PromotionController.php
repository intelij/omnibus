<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Promotion::class);

        return response()->json(
            Promotion::withCount('redemptions')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', Promotion::class);

        $data = $this->validateData($request);
        $data['code'] = Str::upper($data['code']);

        $promo = Promotion::create($data);
        Audit::record('promotion.created', $promo);

        return response()->json($promo, 201);
    }

    public function show(Promotion $promotion)
    {
        $this->authorize('view', $promotion);

        return response()->json($promotion->loadCount('redemptions'));
    }

    public function update(Request $request, Promotion $promotion)
    {
        $this->authorize('update', $promotion);

        $data = $this->validateData($request, true);
        if (isset($data['code'])) {
            $data['code'] = Str::upper($data['code']);
        }

        $promotion->update($data);
        Audit::record('promotion.updated', $promotion);

        return response()->json($promotion);
    }

    public function destroy(Promotion $promotion)
    {
        $this->authorize('delete', $promotion);
        $promotion->delete();
        Audit::record('promotion.deleted', $promotion);

        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:40'],
            'name' => [$rule, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => [$rule, 'in:percent,fixed'],
            'value' => [$rule, 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['sometimes', 'in:active,paused'],
            'applies_to' => ['sometimes', 'in:all,route,date'],
            'constraints' => ['nullable', 'array'],
        ]);
    }
}
