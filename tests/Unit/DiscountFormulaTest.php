<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\DiscountFormula;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Unit tests for DiscountFormula. */
#[TestDox('DiscountFormula')]
class DiscountFormulaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validityCases(): iterable
    {
        yield 'add' => ['+10', true];
        yield 'subtract' => ['-10', true];
        yield 'set' => ['=10', true];
        yield 'multiply' => ['*2', true];
        yield 'percent' => ['10%', true];
        yield 'decimal add' => ['+1.50', true];
        yield 'decimal percent' => ['12.5%', true];
        yield 'surrounding whitespace is trimmed' => ['  +10  ', true];

        yield 'empty' => ['', false];
        yield 'bare number' => ['10', false];
        yield 'letters' => ['abc', false];
        yield 'operator without number' => ['+', false];
        yield 'percent without number' => ['%', false];
        yield 'mixed op and percent' => ['+10%', false];
        yield 'double operator' => ['++10', false];
        yield 'negative percent' => ['-10%', false];
    }

    #[Test]
    #[TestDox('recognizes valid and invalid formula syntax')]
    #[DataProvider('validityCases')]
    public function validates_syntax(string $formula, bool $valid): void
    {
        $this->assertSame($valid, (new DiscountFormula($formula))->isValid());
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function applyCases(): iterable
    {
        yield 'add to subtotal' => ['+10', 100.0, 110.0];
        yield 'subtract from subtotal' => ['-30', 100.0, 70.0];
        yield 'subtract clamps at zero' => ['-200', 100.0, 0.0];
        yield 'set overrides subtotal' => ['=50', 100.0, 50.0];
        yield 'multiply' => ['*2', 100.0, 200.0];
        yield 'multiply by fraction' => ['*0.5', 40.0, 20.0];
        yield 'percent of subtotal' => ['10%', 200.0, 20.0];
        yield 'percent rounds to two places' => ['10%', 33.33, 3.33];
        yield 'invalid formula leaves subtotal untouched' => ['nonsense', 100.0, 100.0];
        yield 'invalid formula clamps a negative subtotal at zero' => ['nonsense', -5.0, 0.0];
    }

    #[Test]
    #[TestDox('applies the formula to a subtotal, rounded and clamped at zero')]
    #[DataProvider('applyCases')]
    public function applies_to_subtotal(string $formula, float $subtotal, float $expected): void
    {
        $this->assertSame($expected, (new DiscountFormula($formula))->apply($subtotal));
    }

    #[Test]
    #[TestDox('returns the subtotal unchanged for an unrecognized operator')]
    public function unrecognized_operator_returns_subtotal(): void
    {
        $formula = new DiscountFormula('+10');

        $op = new \ReflectionProperty(DiscountFormula::class, 'op');
        $op->setValue($formula, '?');

        $this->assertSame(100.0, $formula->apply(100.0));
    }
}
