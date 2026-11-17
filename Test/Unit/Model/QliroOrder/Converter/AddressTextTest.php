<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressText;

/**
 * The checks below copy Magento\Customer\Model\Validator\{City,Name,Telephone}: 2.4.8 (every patch,
 * same as Mage-OS 1.1) and 2.4.9 (same as Mage-OS 3.x). Mage-OS 1.3+ and 2.x have the 2.4.9 city
 * with the 2.4.8 phone, which both sets cover.
 */
class AddressTextTest extends TestCase
{
    private const CITY_248 = '/(?:[\p{L}\p{M}\s\-\']{1,100})/u';
    private const CITY_249 = '/^[\p{L}\p{M}\d\s\-_\'’\.,&\(\)]{1,100}$/u';
    private const NAME = '/(?:[\p{L}\p{M}\,\-\_\.\'’`&\s\d]){1,255}+/u';
    private const TELEPHONE_248 = '/(?:[\d\s\+\-\()]{1,20})/u';
    private const TELEPHONE_249 = '/^[\d\s+().\/ -]+$/u';

    /** 2.4.8 takes the first match and wants it to be the whole value */
    private static function firstMatchIsWhole(string $pattern, string $value): bool
    {
        return $value === '' || !preg_match($pattern, $value, $m) || $m[0] === $value;
    }

    public static function cities(): array
    {
        return [
            'plain' => ['Stockholm', 'Stockholm'],
            'nordic letters' => ['Malmö', 'Malmö'],
            'decomposed umlaut keeps its mark' => ["Malmo\u{0308}", "Malmo\u{0308}"],
            'hyphen and apostrophe' => ["Saint-Jean-d'Angély", "Saint-Jean-d'Angély"],
            'typographic apostrophe' => ['L’Aquila', "L'Aquila"],
            'dot' => ['St. Gallen', 'St Gallen'],
            'digits' => ['Malmö 2', 'Malmö'],
            'parentheses and comma' => ['Saint-Denis (Réunion), FR', 'Saint-Denis Réunion FR'],
            'markup and emoji' => ["Malmö <script>&' Ängelholm 🙂", "Malmö script ' Ängelholm"],
            'nothing left' => ['123 !!', ''],
            'blank' => ['', ''],
            'missing' => [null, ''],
            'long' => [str_repeat('Å', 150), str_repeat('Å', 100)],
            'unicode spaces and format marks' => ["Gäv\u{200D}le\u{00A0}Stad\u{2028}\u{FEFF}", 'Gäv le Stad'],
            'leading combining mark' => ["\u{0308}Umeå", "\u{0308}Umeå"],
            'fullwidth digits' => ['Luleå １２', 'Luleå'],
        ];
    }

    #[DataProvider('cities')]
    public function testCity(?string $city, string $expected): void
    {
        $result = AddressText::city($city);

        self::assertSame($expected, $result);
        self::assertTrue(self::firstMatchIsWhole(self::CITY_248, $result), "2.4.8 refuses '$result'");
        self::assertTrue($result === '' || preg_match(self::CITY_249, $result) === 1, "2.4.9 refuses '$result'");
    }

    public static function names(): array
    {
        return [
            'plain' => ['Anna', 'Anna'],
            'nordic and hyphen' => ['Åsa-Britt', 'Åsa-Britt'],
            'apostrophes' => ["O’Brien d'Arc", "O’Brien d'Arc"],
            'slash' => ['Anna/Maria', 'Anna Maria'],
            'parentheses' => ['Hans (Jr)', 'Hans Jr'],
            'punctuation and emoji' => ['Test! 🙂', 'Test'],
            'nothing left' => ['!!!', ''],
            'missing' => [null, ''],
            'long' => [str_repeat('a', 300), str_repeat('a', 255)],
            'unicode spaces' => ["Anna\u{00A0}Lena\u{2028}", 'Anna Lena'],
            'fullwidth digits' => ['Anna １', 'Anna'],
        ];
    }

    #[DataProvider('names')]
    public function testName(?string $name, string $expected): void
    {
        $result = AddressText::name($name);

        self::assertSame($expected, $result);
        self::assertTrue(self::firstMatchIsWhole(self::NAME, $result), "Magento refuses '$result'");
    }

    public static function telephones(): array
    {
        return [
            'plain' => ['+46701234567', '+46701234567'],
            'spaced' => ['+46 70-123 45 67', '+46 70-123 45 67'],
            'trunk prefix' => ['+46 (0)70-123', '+46 (0)70-123'],
            'dots and slash' => ['070.123/45', '070 123 45'],
            'too long spaced' => ['+46 70 123 45 67 89 10', '+467012345678910'],
            'too long digits' => [str_repeat('1', 25), ''],
            'letters only' => ['n/a', ''],
            'unicode spaces' => ["+46\u{00A0}70\u{2028}123", '+46 70 123'],
            'fullwidth digits' => ['１２３', ''],
            'blank' => ['', ''],
            'missing' => [null, ''],
        ];
    }

    #[DataProvider('telephones')]
    public function testTelephone(?string $telephone, string $expected): void
    {
        $result = AddressText::telephone($telephone);

        self::assertSame($expected, $result);
        self::assertTrue(self::firstMatchIsWhole(self::TELEPHONE_248, $result), "2.4.8 refuses '$result'");
        self::assertTrue($result === '' || preg_match(self::TELEPHONE_249, $result) === 1, "2.4.9 refuses '$result'");
        self::assertLessThanOrEqual(255, mb_strlen($result));
    }
}
