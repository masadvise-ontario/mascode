<?php

namespace Civi\Mascode\Test\Unit\Util;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Util\CodeGenerator;

/**
 * Tests for CodeGenerator::generate().
 *
 * The previous version of this file tested an imagined instance API
 * (generateCode(), isValidCodeFormat(), extractYearFromCode(), ...) that never
 * existed — CodeGenerator only exposes a static generate($caseType). It errored
 * on every run and had never actually executed in CI, because CI never
 * installed phpunit. This tests the real method.
 *
 * generate() reads and writes \Civi::settings(), so it needs a bootstrapped
 * CiviCRM. When the phpunit bootstrap could not load one — the case in CI — the
 * whole class self-skips, the same pattern the Integration suite uses. Where
 * CiviCRM IS present, each test snapshots the year's counter setting and
 * restores it in tearDown, so exercising the generator never advances the real
 * MAS code sequence.
 *
 * @covers \Civi\Mascode\Util\CodeGenerator
 */
class CodeGeneratorTest extends TestCase
{
    /** @var array<string,mixed> setting name => value, captured for restore */
    private array $settingBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('civicrm_initialize') || !class_exists('\Civi')) {
            $this->markTestSkipped('CiviCRM not bootstrapped — CodeGenerator::generate() needs \Civi::settings()');
        }

        $year = date('y');
        foreach (['mascode_last_service_request' . $year, 'mascode_last_project' . $year] as $key) {
            $this->settingBackup[$key] = \Civi::settings()->get($key);
        }
    }

    protected function tearDown(): void
    {
        // Restore the counters so running the tests never advances the real
        // MAS code sequence.
        foreach ($this->settingBackup as $key => $value) {
            \Civi::settings()->set($key, $value);
        }
        $this->settingBackup = [];

        parent::tearDown();
    }

    public function testServiceRequestCodeFormat(): void
    {
        $code = CodeGenerator::generate('service_request');
        // R + two-digit year + three-digit sequence.
        $this->assertMatchesRegularExpression('/^R\d{5}$/', $code);
        $this->assertStringStartsWith('R' . date('y'), $code);
    }

    public function testProjectCodeFormat(): void
    {
        $code = CodeGenerator::generate('project');
        $this->assertMatchesRegularExpression('/^P\d{5}$/', $code);
        $this->assertStringStartsWith('P' . date('y'), $code);
    }

    public function testConsecutiveCodesIncrement(): void
    {
        $first = CodeGenerator::generate('service_request');
        $second = CodeGenerator::generate('service_request');
        $this->assertNotSame($first, $second, 'consecutive codes must differ');
        $this->assertSame(
            1,
            ((int) substr($second, -3)) - ((int) substr($first, -3)),
            'the sequence should advance by exactly one'
        );
    }

    public function testInvalidCaseTypeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid case type');
        CodeGenerator::generate('not_a_real_type');
    }
}
