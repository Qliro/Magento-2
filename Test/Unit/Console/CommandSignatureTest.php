<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento loads every module's console commands to build the CLI, so one command whose execute()
 * does not match the Symfony base class fatals the whole of bin/magento, cron and setup:upgrade
 * with it. Magento 2.4.9 ships symfony/console 7, where the base declares ": int"; 5.4 and 6.4,
 * which 2.4.6 to 2.4.8 ship, declare no return type at all.
 *
 * Declaring ": int" is correct on every one of them, because PHP lets a child add a return type
 * where the parent has none but not drop one the parent declares. That is the rule these tests
 * pin, in both directions (PLIN-382).
 */
class CommandSignatureTest extends TestCase
{
    /**
     * Every command declares what the installed Symfony base requires, or narrows it where the
     * base declares nothing.
     *
     * A command that drops the return type fails this on the Symfony versions whose base declares
     * none, and on the ones that do declare it the class cannot even be loaded, so the suite dies
     * outright. Either way it cannot pass unnoticed. The violation is a compile-time fatal rather
     * than a catchable Error, which is why it is not asserted directly here.
     *
     * @dataProvider commandProvider
     */
    public function testExecuteIsCompatibleWithTheSymfonyBase(string $class): void
    {
        $ours = (new \ReflectionClass($class))->getMethod('execute')->getReturnType();
        $base = (new \ReflectionMethod(Command::class, 'execute'))->getReturnType();

        self::assertNotNull($ours, $class . '::execute() declares no return type');
        self::assertSame('int', (string)$ours, $class . '::execute() must return int');

        if ($base !== null) {
            self::assertSame(
                (string)$base,
                (string)$ours,
                $class . '::execute() must match the base class on this Symfony version'
            );
        }
    }

    /**
     * Adding the return type where the base has none is allowed, so the same source works on the
     * older Symfony versions 2.4.6 to 2.4.8 ship.
     */
    public function testDeclaringIntIsAcceptedRegardlessOfTheBase(): void
    {
        $class = 'SignatureAccepted' . random_int(1000, 9999);

        eval(sprintf(
            'class %s extends \\%s { protected function execute(\\%s $i, \\%s $o): int { return 0; } }',
            $class,
            Command::class,
            InputInterface::class,
            OutputInterface::class
        ));

        self::assertSame('int', (string)(new \ReflectionClass($class))->getMethod('execute')->getReturnType());
    }

    /** @return array<string, array{string}> */
    public static function commandProvider(): array
    {
        $commands = [];

        foreach (glob(__DIR__ . '/../../../Console/*.php') ?: [] as $file) {
            $class = 'Qliro\\QliroOne\\Console\\' . basename($file, '.php');

            if (is_subclass_of($class, Command::class)) {
                $commands[basename($file, '.php')] = [$class];
            }
        }

        self::assertNotEmpty($commands, 'no console commands were discovered');

        return $commands;
    }
}
