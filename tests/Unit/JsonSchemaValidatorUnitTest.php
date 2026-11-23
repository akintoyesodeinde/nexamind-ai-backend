<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Agents\Services\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorUnitTest extends TestCase
{
    public function test_validator_accepts_valid_payload_and_rejects_missing_extra_and_invalid_types(): void
    {
        $validator = new JsonSchemaValidator;
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['query'],
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
                'top_k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                'channel' => ['type' => 'string', 'enum' => ['internal_portal', 'compliance_archive']],
            ],
        ];

        $this->assertSame([], $validator->validate([
            'query' => 'remote work policy',
            'top_k' => 5,
            'channel' => 'compliance_archive',
        ], $schema));

        $missing = $validator->validate(['top_k' => 5], $schema);
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString("Missing required argument 'query'", $missing[0]);

        $extraAndBadRange = $validator->validate([
            'query' => 'ok',
            'top_k' => 99,
            'sql' => 'DROP TABLE users',
        ], $schema);
        $this->assertCount(2, $extraAndBadRange);
    }
}
