<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Config;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface as ProductMetadata;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class Exporter
 */
class Exporter
{
    /**
     * Config path prefix for all Qliro_Qliroone config entries.
     */
    private const CONFIG_PATH = 'payment/qliroone/';
    private const CONFIG_PATH_API_KEY = self::CONFIG_PATH . 'qliro_api/merchant_api_key';

    private const CONFIG_PATH_API_SECRET = self::CONFIG_PATH . 'qliro_api/merchant_api_secret';

    public const DOWLOAD_FILE_NAME = 'qliroone_logs.zip';

    protected array $allowedLogFiles = [
        'qliroone.log',
        'qliroone_error.log'
    ];

    private const PHP_VERSION = 'PHP: ';

    /**
     * Class constructor
     */
    public function __construct(
        private readonly ConfigCollectionFactory $configCollectionFactory,
        private readonly DirectoryList           $directoryList,
        private readonly ProductMetadata         $productMetadata
    ) {
    }

    /**
     * Export all core_config_data entries only for Qliro_Qliroone module
     * into the CSV file in var/log and return an absolute file path.
     *
     * @return string
     * @throws \RuntimeException|FileSystemException
     */
    public function getConfigs(): string
    {
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('path', ['like' => self::CONFIG_PATH . '%']);

        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        $exportDir = $logDir . '/Qliro';

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $timestamp = date('Ymd_His');
        $filePath = sprintf('%s/Config_%s.csv', $exportDir, $timestamp);

        $fp = fopen($filePath, 'w');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open file for writing: ' . $filePath);
        }

        fputcsv($fp, ['scope', 'scope_id', 'path', 'value']);

        foreach ($collection as $configRow) {
            if ($configRow->getData('path') === self::CONFIG_PATH_API_KEY ||
                $configRow->getData('path') === self::CONFIG_PATH_API_SECRET) {
                $configRow->setData('value', '******');
            }
            fputcsv(
                $fp,
                [
                    $configRow->getData('scope'),
                    $configRow->getData('scope_id'),
                    $configRow->getData('path'),
                    $configRow->getData('value'),
                ]
            );
        }

        fputs($fp, $this->getPhpVersion() . PHP_EOL);
        fputs($fp, $this->getProductVersion());

        fclose($fp);

        return basename(dirname($filePath)) . '/' . basename($filePath);
    }

    /**
     * Get log files and zip them into a single file
     *
     * @return string
     * @throws LocalizedException
     */
    public function getLogFiles(): string
    {
        $zipFilePath = sprintf(
            '%s/%s/%s',
            $this->directoryList->getPath(DirectoryList::VAR_DIR),
            DirectoryList::LOG,
            self::DOWLOAD_FILE_NAME
        );

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new LocalizedException(__('Unable to create zip archive.'));
        }

        $filesAdded = false;
        foreach ($this->allowedLogFiles as $logFile) {
            $logFilePath = sprintf(
                '%s/%s/%s',
                $this->directoryList->getPath(DirectoryList::VAR_DIR),
                DirectoryList::LOG,
                $logFile
            );

            if (file_exists($logFilePath)) {
                $zip->addFile($logFilePath, $logFile);
                $filesAdded = true;
            }
        }

        $zip->close();

        if (!$filesAdded) {
            throw new LocalizedException(__('No log files were found to download.'));
        }

        return basename($zipFilePath);
    }

    public function getPhpVersion(): string
    {
        return self::PHP_VERSION . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    public function getProductVersion(): string
    {
        return $this->productMetadata->getName() . ': ' . $this->productMetadata->getVersion();
    }
}
