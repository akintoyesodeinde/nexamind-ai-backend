<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SqlServerSchemaAndVectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_server_uses_uniqueidentifier_datetime2_and_decimal_precision(): void
    {
        /** @var object{DATA_TYPE: string}|null $idCol */
        $idCol = DB::selectOne("
            SELECT DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'id'
        ");
        $this->assertSame('uniqueidentifier', $idCol?->DATA_TYPE);

        /** @var object{DATA_TYPE: string, DATETIME_PRECISION: int}|null $createdCol */
        $createdCol = DB::selectOne("
            SELECT DATA_TYPE, DATETIME_PRECISION
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'created_at'
        ");
        $this->assertSame('datetime2', $createdCol?->DATA_TYPE);
        $this->assertSame(3, (int) ($createdCol?->DATETIME_PRECISION ?? 0));

        /** @var object{DATA_TYPE: string, NUMERIC_PRECISION: int, NUMERIC_SCALE: int}|null $costCol */
        $costCol = DB::selectOne("
            SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = 'tenant_settings' AND COLUMN_NAME = 'monthly_cost_budget_usd'
        ");
        $this->assertSame('decimal', $costCol?->DATA_TYPE);
        $this->assertSame(18, (int) ($costCol?->NUMERIC_PRECISION ?? 0));
        $this->assertSame(6, (int) ($costCol?->NUMERIC_SCALE ?? 0));
    }

    public function test_sql_server_2025_native_vector_distance_executes_cleanly(): void
    {
        $v1 = '['.implode(',', array_fill(0, 1536, '0.1')).']';
        $v2 = '['.implode(',', array_fill(0, 1536, '0.1')).']';

        /** @var object{dist: float}|null $row */
        $row = DB::selectOne(
            "SELECT VECTOR_DISTANCE('cosine', CAST(? AS VECTOR(1536)), CAST(? AS VECTOR(1536))) AS dist",
            [$v1, $v2],
        );

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, (float) $row->dist, 1e-6);
    }
}
