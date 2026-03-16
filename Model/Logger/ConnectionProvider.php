<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Logger;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;

class ConnectionProvider
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|null
     */
    private $connection = null;

    /**
     * Class constructor
     *
     * @param DeploymentConfig $deploymentConfig
     * @param ConnectionFactory $connectionFactory
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Get a log DB connection that uses same config as default connection, but is separate
     *
     * @return AdapterInterface
     * @throws \DomainException
     */
    public function getConnection()
    {
        if (!$this->connection) {
            $connectionName = ResourceConnection::DEFAULT_CONNECTION;

            $connectionConfig = $this->deploymentConfig->get(
                ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/' . $connectionName
            );

            if ($connectionConfig) {
                $this->connection = $this->connectionFactory->create($connectionConfig);
            } else {
                throw new \DomainException("Connection '$connectionName' is not defined");
            }

        }

        return $this->connection;
    }
}
