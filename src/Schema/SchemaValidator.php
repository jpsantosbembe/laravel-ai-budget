<?php

namespace Bembe\AiBudget\Schema;

interface SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function matches(mixed $value, array $schema): bool;
}
