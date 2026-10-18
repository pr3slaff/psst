<?php

namespace Pr3slaff\Psst\Facades;

/**
 * @method static \Pr3slaff\Psst\Result info(string $message, array $context = [])
 * @method static \Pr3slaff\Psst\Result warning(string $message, array $context = [])
 * @method static \Pr3slaff\Psst\Result success(string $message, array $context = [])
 * @method static \Pr3slaff\Psst\Result danger(string $message, array $context = [])
 * @method static \Pr3slaff\Psst\Psst force()
 * @method static void metric(string $key, string $label, callable $callback, array $options = [])
 */
final class Psst extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor(): string { return \Pr3slaff\Psst\Psst::class; }
}
