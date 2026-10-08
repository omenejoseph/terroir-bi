<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SqlDate;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SqlDateTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function drivers(): array
    {
        return [
            'sqlite' => ['sqlite', "strftime('%Y-%m', o.created_at)"],
            'mysql' => ['mysql', "DATE_FORMAT(o.created_at, '%Y-%m')"],
            'mariadb' => ['mariadb', "DATE_FORMAT(o.created_at, '%Y-%m')"],
            'pgsql' => ['pgsql', "to_char(o.created_at, 'YYYY-MM')"],
        ];
    }

    /** @dataProvider drivers */
    #[DataProvider('drivers')]
    public function test_month_bucket_expression_per_driver(string $driver, string $expected): void
    {
        DB::shouldReceive('connection')->andReturn(new class($driver)
        {
            public function __construct(private readonly string $driver) {}

            public function getDriverName(): string
            {
                return $this->driver;
            }
        });

        $this->assertSame($expected, SqlDate::month('o.created_at'));
    }
}
