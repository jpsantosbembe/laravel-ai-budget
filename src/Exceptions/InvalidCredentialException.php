<?php

namespace Bembe\AiBudget\Exceptions;

/**
 * The provider rejected the API key (401). The runner disables the offending
 * credential on the spot rather than letting every subsequent run fail the
 * same way.
 */
class InvalidCredentialException extends \RuntimeException {}
