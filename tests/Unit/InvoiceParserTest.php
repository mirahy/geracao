<?php

namespace Tests\Unit;

use App\Services\Documents\InvoiceParser;
use PHPUnit\Framework\TestCase;

class InvoiceParserTest extends TestCase
{
    private function parse(string $fixture): array
    {
        return (new InvoiceParser)->parse(file_get_contents(__DIR__.'/../Fixtures/'.$fixture));
    }

    public function test_parses_generator_invoice(): void
    {
        $d = $this->parse('fatura_geradora_2026_11.txt');

        $this->assertSame('91735805198', $d['uc_number']);
        $this->assertSame('2496572-5', $d['code']);
        $this->assertSame('2026-11-01', $d['reference_month']->toDateString());
        $this->assertSame('2026-12-26', $d['due_date']->toDateString());
        $this->assertSame(110.56, $d['total_amount']);
        $this->assertSame(31, $d['billing_days']);
        $this->assertSame('020.117.044', $d['invoice_number']);
        $this->assertSame('50261115413826000150660020201170441002760021', $d['access_key']);
        $this->assertSame('D6253983426', $d['meter_number']);
        $this->assertSame(234.0, $d['consumption_kwh']);
        $this->assertSame(911.0, $d['injected_kwh']);
        $this->assertSame(233.0, $d['compensated_kwh']);
        $this->assertSame(737.0, $d['credit_balance_kwh']);
        $this->assertSame(306.5, $d['energy_amount']);
        $this->assertSame(305.19, $d['compensation_credit_amount']);
        $this->assertSame(48.38, $d['compensation_fee_amount']);
        $this->assertSame(60.87, $d['cip_amount']);
        $this->assertSame(16.87, $d['cost_distribution']);
        $this->assertSame(61.19, $d['cost_taxes']);
        $this->assertSame('JARDIM OASIS', $d['neighborhood']);
        $this->assertSame('NAVIRAI', $d['city']);
        $this->assertCount(4, $d['items']);
        $this->assertEqualsWithDelta($d['total_amount'], array_sum(array_column($d['items'], 'amount')), 0.001);
    }

    public function test_parses_receiver_invoice_with_penalties_and_credit_origin(): void
    {
        $d = $this->parse('fatura_recebedora_a_2026_11.txt');

        $this->assertSame('25953005179', $d['uc_number']);
        $this->assertSame('3893838-7', $d['code']);
        $this->assertSame(83.46, $d['total_amount']);
        $this->assertSame(162.0, $d['consumption_kwh']);
        $this->assertSame(141.0, $d['compensated_kwh']);
        $this->assertSame(1.27, $d['other_charges_amount']); // multa + atualização + juros
        $this->assertSame('mUC 9/2026 mPT', $d['credit_origin']);
        $this->assertSame('2026-09-01', $d['credit_cycle']->toDateString());
        $this->assertSame('2026-11-17', $d['paid_at']->toDateString());
        $this->assertTrue($d['is_contingency']);
        $this->assertCount(7, $d['items']);
        $this->assertEqualsWithDelta($d['total_amount'], array_sum(array_column($d['items'], 'amount')), 0.001);
    }

    public function test_parses_receiver_invoice_with_multiline_address(): void
    {
        $d = $this->parse('fatura_recebedora_b_2026_11.txt');

        $this->assertSame('166533905167', $d['uc_number']);
        $this->assertSame('CAMPO GRANDE', $d['city']);
        $this->assertSame(112.27, $d['total_amount']);
        $this->assertSame(41, $d['billing_days']);
        $this->assertSame(290.0, $d['compensated_kwh']);
        $this->assertSame(324.0, $d['credit_balance_kwh']);
        $this->assertStringContainsString('APTO 311', $d['address']);
    }
}
