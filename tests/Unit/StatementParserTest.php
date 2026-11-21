<?php

namespace Tests\Unit;

use App\Services\Documents\StatementParser;
use PHPUnit\Framework\TestCase;

class StatementParserTest extends TestCase
{
    private function parse(string $fixture): array
    {
        return (new StatementParser)->parse(file_get_contents(__DIR__.'/../Fixtures/'.$fixture));
    }

    public function test_parses_november_statement(): void
    {
        $d = $this->parse('demonstrativo_2026_11.txt');

        $this->assertSame('91735805198', $d['uc_number']);
        $this->assertSame('2026-11-01', $d['reference_month']->toDateString());
        $this->assertCount(13, $d['history']);
        $this->assertSame(234.0, $d['history'][0]['measured_kwh']);
        $this->assertSame(911.0, $d['history'][0]['injected_kwh']);

        $m = $d['movements'];
        $this->assertSame(669.0, $m['balance_previous_kwh']);
        $this->assertSame(911.0, $m['injected_kwh']);
        $this->assertSame(233.0, $m['compensated_kwh']);

        $this->assertCount(2, $d['transfers']);
        $this->assertSame(['25953005179', 40, 271.0], [$d['transfers'][0]['uc_number'], $d['transfers'][0]['percentage'], $d['transfers'][0]['kwh']]);
        $this->assertSame(['166533905167', 50, 339.0], [$d['transfers'][1]['uc_number'], $d['transfers'][1]['percentage'], $d['transfers'][1]['kwh']]);
        $this->assertCount(3, $d['previous_balance_composition']);
    }

    public function test_parses_statement_with_single_transfer(): void
    {
        $d = $this->parse('demonstrativo_2026_08.txt');

        $this->assertSame(835.0, $d['movements']['injected_kwh']);
        $this->assertSame(231.0, $d['movements']['transferred_kwh']);
        $this->assertCount(1, $d['transfers']);
        $this->assertSame('31085605152', $d['transfers'][0]['uc_number']);
    }
}
