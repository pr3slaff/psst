<?php

namespace Pr3slaff\Psst;

final class Metrics
{
    private array $definitions = [];
    public function register(string $key, string $label, callable $callback, array $options = []): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $key) || mb_strlen($label) > 200 || $label === '' || (!isset($this->definitions[$key]) && count($this->definitions) >= 20)) { return; }
        $this->definitions[$key] = compact('label', 'callback', 'options');
    }
    public function all(): array
    {
        $metrics = [];
        foreach ($this->definitions as $key => $definition) {
            $metrics[] = $this->compute($key, $definition);
        }
        return $metrics;
    }
    private function compute(string $key, array $definition): array
    {
        $options = $definition['options'];
        $unit = $options['unit'] ?? null;
        $unit = is_string($unit) ? mb_substr($unit, 0, 64) : null;
        $base = ['key' => $key, 'label' => $definition['label'], 'value' => null, 'type' => 'string', 'unit' => $unit, 'computed_at' => null, 'is_stale' => true, 'error' => null];
        $last = null;
        $cache = null;
        $cacheKey = 'psst.metric.'.hash('sha256', (string) config('app.name').'|'.$key);
        try {
            $cache = app('cache')->store(config('psst.metric_cache_store'));
            $last = $cache->get($cacheKey);
            $ttl = max(0, (int) ($options['cache_ttl'] ?? config('psst.metric_cache_ttl', 60)));
            if (is_array($last) && ($last['expires_at'] ?? 0) > time()) { return array_replace($base, $last['metric']); }
            $value = ($definition['callback'])();
            if ((!is_string($value) && !is_bool($value) && !is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) { throw new \UnexpectedValueException; }
            if (is_string($value)) { $value = mb_substr(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, 4000); }
            $metric = array_replace($base, ['value' => $value, 'type' => is_bool($value) ? 'boolean' : (is_string($value) ? 'string' : 'number'), 'computed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'is_stale' => false]);
            // Last good result has no expiration; freshness is tracked independently.
            $cache->forever($cacheKey, ['expires_at' => time() + $ttl, 'metric' => $metric]);
            return $metric;
        } catch (\Throwable) {
            return array_replace($base, is_array($last) ? ($last['metric'] ?? []) : [], ['label' => $definition['label'], 'unit' => $unit, 'is_stale' => true, 'error' => 'Metric unavailable']);
        }
    }
}
