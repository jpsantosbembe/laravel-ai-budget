<?php

namespace Bembe\AiBudget\Exceptions;

/**
 * The estimated cost of a run exceeded the profile's ceiling. Thrown BEFORE
 * any tokens are spent, so the caller can lower the input, raise the ceiling
 * or abort without having paid for anything.
 */
class BudgetExceededException extends \RuntimeException {}
