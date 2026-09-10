<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Logger\Processor;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Processor\Redact;
use Qliro\QliroOne\Model\Logger\Redactor;

/**
 * @see \Qliro\QliroOne\Model\Logger\Processor\Redact
 */
class RedactTest extends TestCase
{
    private const API_KEY = 'live-8f14e45fceea167a5a36dedd4bea2543';
    private const EMAIL = 'anna.andersson@example.com';

    /**
     * Monolog 3, which Magento 2.4.9 ships: the record is an object and is replaced by a masked one.
     */
    public function testMasksAnObjectRecord(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'qliroone_logger',
            Level::Debug,
            'sent to ' . self::EMAIL,
            [
                'tags' => Redactor::TAG_SENSITIVE,
                'extra' => ['body' => ['MerchantApiKey' => self::API_KEY, 'Customer' => ['Email' => self::EMAIL]]],
            ],
            ['request_id' => 'abc']
        );

        $processed = (new Redact(new Redactor()))($record);

        self::assertInstanceOf(LogRecord::class, $processed);
        self::assertStringNotContainsString(self::EMAIL, $processed->message);
        self::assertSame(Redactor::MASK, $processed->context['extra']['body']['MerchantApiKey']);
        self::assertSame(Redactor::MASK, $processed->context['extra']['body']['Customer']['Email']);
        self::assertSame('abc', $processed->extra['request_id']);
        self::assertSame(Level::Debug, $processed->level);
        self::assertSame('qliroone_logger', $processed->channel);
    }

    /**
     * Monolog 2, which the older Magento versions the module supports ship: the record is an array.
     */
    public function testMasksAnArrayRecord(): void
    {
        $record = [
            'message' => 'sent to ' . self::EMAIL,
            'context' => [
                'tags' => Redactor::TAG_SENSITIVE,
                'extra' => ['body' => ['MerchantApiKey' => self::API_KEY]],
            ],
            'level_name' => 'DEBUG',
            'extra' => ['Authorization' => 'QliroOne ' . self::API_KEY],
        ];

        $processed = (new Redact(new Redactor()))($record);

        self::assertIsArray($processed);
        self::assertStringNotContainsString(self::EMAIL, $processed['message']);
        self::assertSame(Redactor::MASK, $processed['context']['extra']['body']['MerchantApiKey']);
        self::assertSame(Redactor::MASK, $processed['extra']['Authorization']);
        self::assertSame('DEBUG', $processed['level_name']);
    }

    /**
     * Monolog lets an exception from a processor out into the business flow, and the whole point of
     * the log handler's own catch is that logging never breaks a checkout. If the masking itself
     * fails, the line survives with nothing readable in it and the flow carries on.
     */
    public function testNeverThrowsAndWritesNothingReadableIfTheMaskingFails(): void
    {
        $redactor = $this->createMock(Redactor::class);
        $redactor->method('isSensitive')->willReturn(false);
        $redactor->method('redactContext')->willThrowException(new \RuntimeException('broken'));
        $redactor->method('redactMessage')->willThrowException(new \RuntimeException('broken'));

        $processed = (new Redact($redactor))([
            'message' => 'sent to ' . self::EMAIL,
            'context' => [
                'tags' => 'checkout',
                'reference' => 'qliroone-42',
                'mark' => 'REST API',
                'process_id' => 1234,
                'extra' => ['body' => ['MerchantApiKey' => self::API_KEY]],
            ],
        ]);

        self::assertSame(Redactor::MASK, $processed['message']);
        self::assertSame([], $processed['extra']);
        self::assertSame(\RuntimeException::class, $processed['context']['redaction_failed']);

        // What the handler reads the row's own columns off, so the line is still findable
        self::assertSame('qliroone-42', $processed['context']['reference']);
        self::assertSame('checkout', $processed['context']['tags']);
        self::assertSame('REST API', $processed['context']['mark']);
        self::assertSame(1234, $processed['context']['process_id']);
    }

    /**
     * The message is masked with the line's own reference held out of it, the same as the context,
     * so an interpolated merchant reference is not mistaken for an identity number.
     */
    public function testKeepsTheReferenceInTheMessageToo(): void
    {
        $processed = (new Redact(new Redactor()))([
            'message' => 'placing order 20260909-0001 for 19850101-1234',
            'context' => ['reference' => '20260909-0001', 'tags' => Redactor::TAG_SENSITIVE],
        ]);

        self::assertStringContainsString('order 20260909-0001', $processed['message']);
        self::assertStringNotContainsString('19850101-1234', $processed['message']);
    }

    /**
     * A line with nothing to hide passes through unchanged, tags and all.
     */
    public function testLeavesALineWithNothingToHide(): void
    {
        $record = [
            'message' => 'Sending request to Qliro Uri: https://pago.qit.nu/checkout/merchantapi/orders',
            'context' => ['tags' => 'checkout', 'reference' => 'qliroone-42', 'extra' => ['status_code' => 200]],
        ];

        $processed = (new Redact(new Redactor()))($record);

        self::assertSame($record['message'], $processed['message']);
        self::assertSame('qliroone-42', $processed['context']['reference']);
        self::assertSame(200, $processed['context']['extra']['status_code']);
    }
}
