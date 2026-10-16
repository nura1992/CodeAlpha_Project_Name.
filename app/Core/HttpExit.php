<?php

namespace MedCore\Core;

/**
 * Signals "the response is complete — stop executing".
 *
 * Thrown instead of calling exit/die so that response finalization stays
 * inside normal control flow (front controller catches it). This keeps the
 * application testable and compatible with non-classic SAPIs (cli-server,
 * php-wasm previews, workers) where exit() would kill the whole runtime.
 */
final class HttpExit extends \Exception
{
}
