<?php

namespace Bembe\AiBudget\Exceptions;

/**
 * The provider rate limited the call (429). A transient failure: queue workers
 * should let their own retry policy handle it.
 */
class RateLimitedException extends \RuntimeException {}
