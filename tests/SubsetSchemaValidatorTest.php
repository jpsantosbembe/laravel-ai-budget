<?php

namespace Bembe\AiBudget\Tests;

use Bembe\AiBudget\Schema\SubsetSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

class SubsetSchemaValidatorTest extends BaseTestCase
{
    public static function cases(): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['label', 'items'],
            'properties' => [
                'label' => ['type' => 'string', 'enum' => ['a', 'b']],
                'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ];

        return [
            'valid' => [$schema, ['label' => 'a', 'items' => [1, 2]], true],
            'missing required key' => [$schema, ['label' => 'a'], false],
            'value outside enum' => [$schema, ['label' => 'z', 'items' => []], false],
            'wrong item type' => [$schema, ['label' => 'b', 'items' => ['x']], false],
            'extra keys are allowed' => [$schema, ['label' => 'a', 'items' => [], 'extra' => 1], true],
            'object where array expected' => [['type' => 'array'], ['k' => 'v'], false],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_validates_the_supported_subset(array $schema, mixed $value, bool $expected): void
    {
        $this->assertSame($expected, (new SubsetSchemaValidator)->matches($value, $schema));
    }
}
