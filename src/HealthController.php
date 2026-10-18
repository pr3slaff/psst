<?php

namespace Pr3slaff\Psst;

use Illuminate\Http\Request;

final class HealthController
{
    public function __invoke(Request $request)
    {
        $key = config('psst.health_key');
        if (!is_string($key) || $key === '') { return response()->json(['message' => 'Not found'], 404); }
        if (!hash_equals($key, (string) $request->header('X-Psst-Key', ''))) { return response()->json(['message' => 'Unauthorized'], 401); }
        try { $metrics = app(Metrics::class)->all(); } catch (\Throwable) { $metrics = []; }
        return response()->json(['schema_version' => 1, 'status' => 'ok', 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'metrics' => $metrics])->header('Cache-Control', 'no-store');
    }
}
