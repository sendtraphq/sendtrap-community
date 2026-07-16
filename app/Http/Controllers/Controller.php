<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Sendtrap\Core\Support\IpAllowList;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * A validation rule that accepts a single IP address or CIDR range.
     */
    protected static function ipRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! IpAllowList::validRule($value)) {
                $fail('"'.$value.'" is not a valid IP address or CIDR range.');
            }
        };
    }
}
