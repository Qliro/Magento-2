<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

/**
 * Address text from Qliro reduced to what Magento's address validators accept on 2.4.8 and 2.4.9
 *
 * Magento\Customer\Model\Validator\* run on a guest's address when the order validation callback
 * checks the quote and when the order is placed. 2.4.8 takes only letters, spaces, `-` and `'` in
 * a city and 20 characters of `0-9 + - ( )` and spaces in a phone, 2.4.9 is looser on both, and
 * names follow the same rule on every version. Each method returns '' when nothing is left.
 */
class AddressText
{
    public const CITY_MAX_LENGTH = 100;

    public const NAME_MAX_LENGTH = 255;

    public const TELEPHONE_MAX_LENGTH = 20;

    /**
     * City with only letters, marks, spaces, `-` and `'`
     *
     * @param string|null $city
     * @return string
     */
    public static function city(?string $city): string
    {
        $city = str_replace('’', "'", (string)$city);

        return self::reduce($city, '/[^\p{L}\p{M}\s\'-]/u', self::CITY_MAX_LENGTH);
    }

    /**
     * First or last name without the characters Magento's name check refuses
     *
     * @param string|null $name
     * @return string
     */
    public static function name(?string $name): string
    {
        return self::reduce((string)$name, '/[^\p{L}\p{M}\s0-9,._\'’`&-]/u', self::NAME_MAX_LENGTH);
    }

    /**
     * Phone of `0-9 + - ( )` and spaces; over 20 characters only the digits and a leading `+`
     *
     * @param string|null $telephone
     * @return string
     */
    public static function telephone(?string $telephone): string
    {
        $telephone = self::reduce((string)$telephone, '/[^0-9\s+()-]/u', PHP_INT_MAX);
        if (mb_strlen($telephone) > self::TELEPHONE_MAX_LENGTH) {
            $telephone = (string)preg_replace('/(?!^\+)[^0-9]/', '', $telephone);
        }

        $valid = preg_match('/[0-9]/', $telephone) && strlen($telephone) <= self::TELEPHONE_MAX_LENGTH;

        return $valid ? $telephone : '';
    }

    /**
     * Disallowed characters become spaces, runs of spaces one, cut to the length
     *
     * @param string $text
     * @param string $disallowed
     * @param int $maxLength
     * @return string
     */
    private static function reduce(string $text, string $disallowed, int $maxLength): string
    {
        $text = (string)preg_replace($disallowed, ' ', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return trim(mb_substr($text, 0, $maxLength));
    }
}
