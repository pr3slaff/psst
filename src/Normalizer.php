<?php

namespace Pr3slaff\Psst;

final class Normalizer
{
    private bool $truncated = false;
    private array $fields;
    private int $remaining = 5000;
    private int $bytesRemaining = 60000;

    public function normalize(string $id, string $level, string $message, array $context, array $fields): array
    {
        $this->truncated = false;
        $this->remaining = 5000;
        $this->bytesRemaining = 60000;
        $this->fields = array_map('strtolower', $fields);
        if (strlen($message) > 16000) { $message = substr($message, 0, 16000); $this->truncated = true; }
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        if ($this->truncated || mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 3988).' [truncated]';
            $this->truncated = true;
        }
        $context = $this->value($context, 0);
        $payload = ['schema_version' => 1, 'event_id' => $id, 'level' => $level, 'message' => $message, 'context' => $context ?: (object) [], 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'truncated' => $this->truncated];
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536) {
            $payload['context'] = ['_psst_truncated' => 'Context exceeded event size limit'];
            $payload['truncated'] = true;
        }
        return $payload;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth >= 8) { $this->truncated = true; return '[truncated: depth limit]'; }
            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 1000 || --$this->remaining < 0 || $this->bytesRemaining <= 0) { $out['_psst_truncated'] = 'Traversal limit'; $this->truncated = true; break; }
                if (is_string($key)) {
                    if (strlen($key) > 1024) { $key = substr($key, 0, 1024); $this->truncated = true; }
                    $key = mb_convert_encoding($key, 'UTF-8', 'UTF-8');
                    if (mb_strlen($key) > 256) { $key = mb_substr($key, 0, 256); $this->truncated = true; }
                    $this->bytesRemaining -= strlen($key);
                }
                $out[$key] = in_array(strtolower((string) $key), $this->fields, true) ? '[REDACTED]' : $this->value($item, $depth + 1);
            }
            return $out;
        }
        // Never invoke arbitrary JsonSerializable, model accessors or exception stringification.
        if (is_object($value) || is_resource($value)) { $this->truncated = true; return '[unsupported value]'; }
        if (is_float($value) && !is_finite($value)) { $this->truncated = true; return '[non-finite number]'; }
        if (is_string($value)) {
            $limit = max(0, min(60000, $this->bytesRemaining));
            $cut = strlen($value) > $limit;
            if ($cut) { $value = substr($value, 0, $limit); $this->truncated = true; }
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $this->bytesRemaining -= strlen($value);
            if ($cut) { return mb_strcut($value, 0, max(0, $limit - 30), 'UTF-8').' [truncated]'; }
        }
        return $value;
    }
}
