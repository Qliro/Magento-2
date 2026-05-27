<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger;

use Psr\Log\LoggerInterface;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

/**
 * Class Manage
 *
 * Provide a layer on top of the psr logger that adds our additional data, a tag and the process id.
 * The tag should be set as early as possibly; all logging after that will include the tag (merchant id,)
 * making it easier to filter the log in sequel pro to see only data relevant to one shopper.
 *
 */
class Manager
{
    /**
     * @var array
     */
    private $marks = [];

    /**
     * @var string
     */
    private $merchantReference;

    /**
     * @var array
     */
    private $tags = [];

    /**
     * Class constructor
     *
     * @param LoggerInterface $psrLogger
     * @param \Qliro\QliroOne\Model\ResourceModel\LogRecord $logResource
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly LoggerInterface $psrLogger,
        private readonly LogRecord $logResource,
        private readonly LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * System is unusable.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function emergency(mixed $message, array $context = []): void
    {
        $this->psrLogger->emergency($message, $this->prepareContext($context));
    }

    /**
     * Action must be taken immediately.
     * Example: Entire website down, database unavailable, etc. This should
     * trigger the SMS alerts and wake you up.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function alert(mixed $message, array $context = []): void
    {
        $this->psrLogger->alert($message, $this->prepareContext($context));
    }

    /**
     * Critical conditions.
     * Example: Application component unavailable, unexpected exception.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function critical(mixed $message, array $context = []): void
    {
        $this->psrLogger->critical($message, $this->prepareContext($context));
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function error(mixed $message, array $context = []): void
    {
        $this->psrLogger->error($message, $this->prepareContext($context));
    }

    /**
     * Exceptional occurrences that are not errors.
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function warning(mixed $message, array $context = []): void
    {
        $this->psrLogger->warning($message, $this->prepareContext($context));
    }

    /**
     * Normal but significant events.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function notice(mixed $message, array $context = []): void
    {
        $this->psrLogger->notice($message, $this->prepareContext($context));
    }

    /**
     * Interesting events.
     * Example: User logs in, SQL logs.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function info(mixed $message, array $context = []): void
    {
        $this->psrLogger->info($message, $this->prepareContext($context));
    }

    /**
     * Detailed debug information.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function debug(mixed $message, array $context = []): void
    {
        $this->psrLogger->debug($message, $this->prepareContext($context));
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->psrLogger->log($level, $message, $this->prepareContext($context));
    }

    /**
     * As soon as possible place a tag in the logger to ensure that all log lines can be linked to a checkout session.
     * The tag should be the merchant reference.
     * We will back-patch the log with the new mercant reference
     *
     * @param mixed $value
     * @return static
     */
    public function setMerchantReference(mixed $value): static
    {
        $this->merchantReference = $value;
        if (!empty($value)) {
            $this->logResource->patchMerchantReference($value);
        }

        return $this;
    }

    /**
     * @param mixed $quote
     * @return static
     */
    public function setMerchantReferenceFromQuote(mixed $quote): static
    {
        if ($quote) {
            try {
                $quoteId = $quote->getEntityId();
                $link = $this->linkRepository->getByQuoteId($quoteId);
                $this->setMerchantReference($link->getReference());
            } catch (\Exception $exception) {
                // Do nothing
            }
        }

        return $this;
    }

    /**
     * Add a tag to any futher logging context
     *
     * @param string $tag
     * @return void
     */
    public function addTag(string $tag): void
    {
        $this->tags[] = $tag;
        $this->tags = array_unique($this->tags);
    }

    /**
     * remove a tag from any futher logging context
     *
     * @param string $tag
     * @return void
     */
    public function removeTag(string $tag): void
    {
        $this->tags = array_diff($this->tags, [$tag]);
    }

    /**
     * Clear all tags from any further logging context
     *
     * @return void
     */
    public function clearTags(): void
    {
        $this->tags = [];
    }

    /**
     * Set message mark.
     * In fact, by setting a mark it pushes previously set mark to the stack.
     * When mark is then set to null, the previously set mark is restored from the stack.
     * It allows to set marks in the folded functions, allowing to restore logger context when exiting the function.
     *
     * @param mixed $mark
     * @return void
     */
    public function setMark(mixed $mark): void
    {
        if ($mark) {
            array_unshift($this->marks, $mark);
        } else {
            array_shift($this->marks);
        }
    }

    /**
     * @param int $levels
     * @return string
     */
    public function getStack(int $levels = 5): string
    {
        $exception = new \Exception;
        $stack = '';
        $skip = strpos($exception->getFile(), 'module-qliroone/') + 16;
        foreach (array_slice($exception->getTrace(), 1, $levels) as $one) {
            $stack .= sprintf('|%s:%s', substr($one['file'], $skip), $one['line']);
        }

        return substr($stack, 1);
    }

    /**
     * @param array $context
     * @return array
     */
    private function prepareContext(array $context): array
    {
        if (!empty($this->merchantReference)) {
            $context['reference'] = $this->merchantReference;
        }

        $contextTags = $this->unpackTags($context['tags'] ?? '');
        $context['tags'] = $this->packTags(array_unique(array_merge($contextTags, $this->tags)));

        if (!empty($this->marks)) {
            $context['mark'] = $this->marks[0];
        }

        $context['process_id'] = \getmypid();

        return $context;
    }

    /**
     * @param array|string $tagsData
     * @return string
     */
    private function packTags(array|string $tagsData): string
    {
        if (is_string($tagsData)) {
            $tagsData = $this->unpackTags($tagsData);
        }

        return is_array($tagsData) && !empty($tagsData)
            ? trim(implode(',', array_map('trim', (array)$tagsData)), ',')
            : '';
    }

    /**
     * @param string $tagsString
     * @return array
     */
    private function unpackTags(string $tagsString): array
    {
        return $tagsString ? explode(',', $tagsString) : [];
    }
}
