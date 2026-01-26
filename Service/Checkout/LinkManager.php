<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Checkout;

use Magento\Framework\Exception\AlreadyExistsException;
use Qliro\QliroOne\Api\Data\LinkInterface as Link;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;

/**
 * Class LinkManager
 */
class LinkManager
{
    /**
     * Class constructor
     *
     * @param LinkRepository $linkRepository
     */
    public function __construct(
        private readonly LinkRepository $linkRepository
    ) {
    }

    /**
     * Deactivate link
     *
     * @param Link $link
     * @return void
     * @throws AlreadyExistsException
     */
    public function deactivate(Link $link): void
    {
        $link->setIsActive(false);
        $link->setQliroOrderId(null);
        $this->linkRepository->save($link);
    }
}
