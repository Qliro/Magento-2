<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Framework\Locale\Resolver;
use Qliro\QliroOne\Api\LanguageMapperInterface;
use Qliro\QliroOne\Model\Management\CountrySelect;

/**
 * QliroOne order language mapper class
 */
class LanguageMapper implements LanguageMapperInterface
{
    private array $languageMap = [
        'sv_SE' => 'sv-se',
        'en_US' => 'en-us',
        'fi_FI' => 'fi-fi',
        'da_DK' => 'da-dk',
        'de_DE' => 'de-de',
        'nb_NO' => 'nb-no',
        'nn_NO' => 'nb-no',
    ];

    private array $countryLanguageMap = [
        'SE' => 'sv-se',
        'DK' => 'da-dk',
        'NO' => 'nb-no',
        'FI' => 'fi-fi',
    ];

    /**
     * Class constructor
     *
     * @param Resolver                 $localeResolver
     * @param CountrySelect            $countrySelect
     */
    public function __construct(
        private readonly Resolver      $localeResolver,
        private readonly CountrySelect $countrySelect
    ) {
    }

    /**
     * @inheirtDoc
     */
    public function getLanguage(): string
    {
        if ($this->countrySelect->isEnabled() && !!$this->countrySelect->getSelectedCountry()) {
            $country = strtoupper($this->countrySelect->getSelectedCountry());
            return $this->countryLanguageMap[$country] ?? 'en-us';
        }

        $locale = $this->localeResolver->getLocale();

        return $this->languageMap[$locale] ?? 'en-us';
    }
}
