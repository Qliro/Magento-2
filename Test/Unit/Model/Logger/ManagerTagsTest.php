<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Logger;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Logger\Redactor;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;

/**
 * @see \Qliro\QliroOne\Model\Logger\Manager::addTag()
 */
class ManagerTagsTest extends TestCase
{
    /**
     * A tag is held by as many scopes as took it, so the inner one ending does not take it from the
     * outer one. Five places now open a sensitive scope, and the masking of every line inside the
     * outer one depends on the tag still being there.
     */
    public function testAScopeInsideAnotherDoesNotTakeTheTagFromIt(): void
    {
        $logged = [];
        $manager = $this->buildManager($logged);

        $manager->addTag(Redactor::TAG_SENSITIVE);
        $manager->addTag(Redactor::TAG_SENSITIVE);
        $manager->removeTag(Redactor::TAG_SENSITIVE);

        $manager->debug('inside the outer scope');
        self::assertSame(Redactor::TAG_SENSITIVE, $logged[0]['tags']);

        $manager->removeTag(Redactor::TAG_SENSITIVE);

        $manager->debug('outside both');
        self::assertSame('', $logged[1]['tags']);
    }

    /**
     * One scope still gives up the tag when it ends, and an unrelated tag is untouched by it.
     */
    public function testOneScopeGivesUpTheTagAndLeavesTheOthers(): void
    {
        $logged = [];
        $manager = $this->buildManager($logged);

        $manager->addTag('checkout');
        $manager->addTag(Redactor::TAG_SENSITIVE);
        $manager->removeTag(Redactor::TAG_SENSITIVE);

        $manager->debug('after the sensitive scope');

        self::assertSame('checkout', $logged[0]['tags']);
    }

    /**
     * Clearing takes every scope with it, whatever depth they were at.
     */
    public function testClearingTakesEveryScope(): void
    {
        $logged = [];
        $manager = $this->buildManager($logged);

        $manager->addTag(Redactor::TAG_SENSITIVE);
        $manager->addTag(Redactor::TAG_SENSITIVE);
        $manager->clearTags();

        $manager->debug('after clearing');

        self::assertSame('', $logged[0]['tags']);
    }

    /**
     * @param array $logged Filled with the context of each line the psr logger was handed
     * @return Manager
     */
    private function buildManager(array &$logged): Manager
    {
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->method('debug')
            ->willReturnCallback(function ($message, array $context = []) use (&$logged): void {
                $logged[] = $context;
            });

        return new Manager(
            $psrLogger,
            $this->createMock(LogRecord::class),
            $this->createMock(LinkRepositoryInterface::class)
        );
    }
}
