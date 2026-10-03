<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function show()
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready()
    {
        $checks = [
            'db' => fn () => DB::select('select 1') !== null,
            'cache' => fn () => cache()->store()->getStore() !== null,
        ];

        $results = [];
        $ok = true;
        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check() ? 'ok' : 'fail';
            } catch (\Throwable) {
                $results[$name] = 'fail';
                $ok = false;
            }
        }

        return response()->json(['status' => $ok ? 'ready' : 'degraded', 'checks' => $results], $ok ? 200 : 503);
    }
}
