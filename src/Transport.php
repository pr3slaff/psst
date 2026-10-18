<?php

namespace Pr3slaff\Psst;

use Illuminate\Support\Facades\Http;

final class Transport
{
    /** @return array{accepted:bool,transient:bool,retry_after:int} */
    public function send(array $payload): array
    {
        $failure = ['accepted' => false, 'transient' => false, 'retry_after' => 0];
        try {
            $url = config('psst.endpoint');
            $key = config('psst.key');
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || !is_string($key) || $key === '') { return $failure; }
            $response = Http::withToken($key)->acceptJson()->asJson()->timeout(1)->connectTimeout(1)->withOptions(['allow_redirects' => false])->post($url, $payload);
            if ($response->successful() && in_array($response->status(), [200, 201], true) && $response->json('data.event_id') === $payload['event_id'] && is_string($response->json('data.id'))) {
                return ['accepted' => true, 'transient' => false, 'retry_after' => 0];
            }
            $retry = trim((string) $response->header('Retry-After'));
            $seconds = ctype_digit($retry) ? (int) $retry : max(0, (strtotime($retry) ?: time()) - time());
            return ['accepted' => false, 'transient' => $response->status() === 429 || $response->status() >= 500, 'retry_after' => min(86400, $seconds)];
        } catch (\Throwable) {
            return ['accepted' => false, 'transient' => true, 'retry_after' => 0];
        }
    }
}
