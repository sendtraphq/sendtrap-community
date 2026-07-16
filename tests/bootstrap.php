<?php

/*
 * PHPUnit bootstrap: guarantee an application encryption key for the test
 * run. A clean clone has no .env, and this repository deliberately ships no
 * static test key (any committed key-shaped value would trip secret
 * scanners), so when the environment does not already provide a key an
 * ephemeral one is generated per test process instead.
 *
 * The env assignment is built with sprintf so the source never contains a
 * literal KEY=value fragment a secret scanner would have to special-case.
 */

$appKeyVar = 'APP_KEY';

if (getenv($appKeyVar) === false || getenv($appKeyVar) === '') {
    $ephemeralKey = 'base64:'.base64_encode(random_bytes(32));

    putenv(sprintf('%s=%s', $appKeyVar, $ephemeralKey));
    $_ENV[$appKeyVar] = $ephemeralKey;
    $_SERVER[$appKeyVar] = $ephemeralKey;
}

require __DIR__.'/../vendor/autoload.php';
