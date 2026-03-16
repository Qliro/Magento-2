<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model;

use Magento\Framework\Locale\Resolver;
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
        'fi_FI' => 'fi-fi',
        'da_DK' => 'da-dk',
        'de_DE' => 'de-de',
        'nb_NO' => 'nb-no',
        'nn_NO' => 'nb-no',
    ];

    private $countryLanguageMap = [
        'SE' => 'sv-se',
        'DK' => 'da-dk',
        'NO' => 'nb-no',
        'FI' => 'fi-fi',
    ];

    /**
     * @var \Magento\Framework\Locale\Resolver
     */
    private $localeResolver;

    /**
     * @var CountrySelect
     */
    private CountrySelect $countrySelect;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\Locale\Resolver $localeResolver
     * @param CountrySelect $countrySelect
     */
    public function __construct(
        Resolver $localeResolver,
        CountrySelect $countrySelect
    ) {
        $this->localeResolver = $localeResolver;
        $this->countrySelect = $countrySelect;
    }

    /**
     * Get a prepared string that contains a QliroOne compatible language
     *
     * @return string
     */
    public function getLanguage()
    {
        if ($this->countrySelect->isEnabled() && !!$this->countrySelect->getSelectedCountry()) {
            $country = strtoupper($this->countrySelect->getSelectedCountry());
            return $this->countryLanguageMap[$country] ?? 'en-us';
        }

        $locale = $this->localeResolver->getLocale();

        return $this->languageMap[$locale] ?? 'en-us';
    }
}
