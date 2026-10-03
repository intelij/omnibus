<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;

class OnboardingController extends Controller
{
    public function show(OnboardingService $onboarding)
    {
        return response()->json($onboarding->progress(tenant()));
    }
}
