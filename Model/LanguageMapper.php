<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Qliro\QliroOne\Api\LanguageMapperInterface;
use Qliro\QliroOne\Model\Management\CountrySelect;

/**
 * QliroOne order language mapper class
 */
class LanguageMapper implements LanguageMapperInterface
{
    private $languageMap = [
        'sv_SE' => 'sv-se',
        'en_US' => 'en-us',
        'en_GB' => 'en-us',
        'fi_FI' => 'fi-fi',
        'da_DK' => 'da-dk',
        'fr_FR' => 'fr-fr',
        'de_DE' => 'de-de',
        'nb_NO' => 'nb-no',
        'nn_NO' => 'nb-no',
        'nl_NL' => 'nl-nl',
    ];

    private $countryLanguageMap = [
        'SE' => 'sv-se',
        'DK' => 'da-dk',
        'NO' => 'nb-no',
        'FI' => 'fi-fi',
        'FR' => 'fr-fr',
        'DE' => 'de-de',
        'NL' => 'nl-nl',
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CountrySelect
     */
    private CountrySelect $countrySelect;

    /**
     * Inject dependencies
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param CountrySelect $countrySelect
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CountrySelect $countrySelect
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->countrySelect = $countrySelect;
    }

    /**
     * Get a prepared string that contains a QliroOne compatible language
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLanguage($storeId = null)
    {
        if ($this->countrySelect->isEnabled() && !!$this->countrySelect->getSelectedCountry()) {
            $country = strtoupper($this->countrySelect->getSelectedCountry());
            return $this->countryLanguageMap[$country] ?? 'en-us';
        }

        $locale = $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $this->languageMap[$locale] ?? 'en-us';
    }
}
