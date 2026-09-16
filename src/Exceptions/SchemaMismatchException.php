<?php

namespace Bembe\AiBudget\Exceptions;

/**
 * The model's output did not satisfy the profile's json_schema, and neither did
 * its answer to the re-ask.
 *
 * The usage of BOTH calls has already been recorded before this is thrown: the
 * provider billed for them, and a run that silently vanished from the usage log
 * would be exactly the run you most need to see.
 */
class SchemaMismatchException extends \RuntimeException {}
