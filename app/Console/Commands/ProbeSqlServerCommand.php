<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProbeSqlServerCommand extends Command
{
    protected $signature = 'nexamind:probe-sqlserver';

    protected $description = 'Verify SQL Server connectivity, ODBC/PDO_SQLSRV driver, and native VECTOR(1536) capabilities';

    public function handle(): int
    {
        try {
            /** @var object{version: string}|null $row */
            $row = DB::selectOne('SELECT @@VERSION AS version');
            $this->info('SQL Server connected cleanly via PDO_SQLSRV.');
            $this->line($row !== null ? $row->version : 'Unknown version');

            $vector = '['.implode(',', array_fill(0, 1536, '0.1')).']';
            /** @var object{dist: float}|null $distRow */
            $distRow = DB::selectOne(
                "SELECT VECTOR_DISTANCE('cosine', CAST(? AS VECTOR(1536)), CAST(? AS VECTOR(1536))) AS dist",
                [$vector, $vector],
            );

            $this->info(sprintf('Native VECTOR(1536) + VECTOR_DISTANCE supported (cosine distance = %s).', $distRow !== null ? (string) $distRow->dist : 'n/a'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('SQL Server probe failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
