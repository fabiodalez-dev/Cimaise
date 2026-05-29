<?php
declare(strict_types=1);

namespace App\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AnalyticsTwigExtension extends AbstractExtension
{
    /**
     * Country code to country name mapping
     */
    private array $countries = [
        'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
        'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands',
        'BE' => 'Belgium', 'CH' => 'Switzerland', 'AT' => 'Austria', 'SE' => 'Sweden', 'NO' => 'Norway',
        'DK' => 'Denmark', 'FI' => 'Finland', 'IE' => 'Ireland', 'PT' => 'Portugal', 'PL' => 'Poland',
        'CZ' => 'Czech Republic', 'HU' => 'Hungary', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'HR' => 'Croatia', 'RO' => 'Romania', 'BG' => 'Bulgaria', 'GR' => 'Greece', 'CY' => 'Cyprus',
        'MT' => 'Malta', 'LU' => 'Luxembourg', 'EE' => 'Estonia', 'LV' => 'Latvia', 'LT' => 'Lithuania',
        'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'IN' => 'India', 'BR' => 'Brazil',
        'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
        'VE' => 'Venezuela', 'EC' => 'Ecuador', 'UY' => 'Uruguay', 'PY' => 'Paraguay', 'BO' => 'Bolivia',
        'RU' => 'Russia', 'UA' => 'Ukraine', 'BY' => 'Belarus', 'MD' => 'Moldova', 'GE' => 'Georgia',
        'AM' => 'Armenia', 'AZ' => 'Azerbaijan', 'KZ' => 'Kazakhstan', 'UZ' => 'Uzbekistan',
        'KG' => 'Kyrgyzstan', 'TJ' => 'Tajikistan', 'TM' => 'Turkmenistan', 'MN' => 'Mongolia',
        'ZA' => 'South Africa', 'EG' => 'Egypt', 'MA' => 'Morocco', 'DZ' => 'Algeria', 'TN' => 'Tunisia',
        'LY' => 'Libya', 'SD' => 'Sudan', 'ET' => 'Ethiopia', 'KE' => 'Kenya', 'UG' => 'Uganda',
        'TZ' => 'Tanzania', 'RW' => 'Rwanda', 'BI' => 'Burundi', 'MW' => 'Malawi', 'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe', 'BW' => 'Botswana', 'NA' => 'Namibia', 'SZ' => 'Eswatini', 'LS' => 'Lesotho',
        'MZ' => 'Mozambique', 'MG' => 'Madagascar', 'MU' => 'Mauritius', 'SC' => 'Seychelles',
        'TH' => 'Thailand', 'VN' => 'Vietnam', 'MY' => 'Malaysia', 'SG' => 'Singapore', 'ID' => 'Indonesia',
        'PH' => 'Philippines', 'LA' => 'Laos', 'KH' => 'Cambodia', 'MM' => 'Myanmar', 'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka', 'MV' => 'Maldives', 'NP' => 'Nepal', 'BT' => 'Bhutan', 'PK' => 'Pakistan',
        'AF' => 'Afghanistan', 'IR' => 'Iran', 'IQ' => 'Iraq', 'SY' => 'Syria', 'JO' => 'Jordan',
        'LB' => 'Lebanon', 'IL' => 'Israel', 'PS' => 'Palestine', 'SA' => 'Saudi Arabia', 'AE' => 'UAE',
        'OM' => 'Oman', 'YE' => 'Yemen', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'KW' => 'Kuwait',
        'TR' => 'Turkey', 'NZ' => 'New Zealand', 'FJ' => 'Fiji', 'PG' => 'Papua New Guinea',
        'SB' => 'Solomon Islands', 'VU' => 'Vanuatu', 'NC' => 'New Caledonia', 'PF' => 'French Polynesia',
        'WS' => 'Samoa', 'KI' => 'Kiribati', 'TO' => 'Tonga', 'MH' => 'Marshall Islands', 'FM' => 'Micronesia',
        'PW' => 'Palau', 'NR' => 'Nauru', 'TV' => 'Tuvalu'
    ];

    /**
     * Country code to flag emoji mapping
     */
    private array $flags = [
        'US' => '🇺🇸', 'GB' => '🇬🇧', 'CA' => '🇨🇦', 'AU' => '🇦🇺', 'DE' => '🇩🇪', 'FR' => '🇫🇷',
        'IT' => '🇮🇹', 'ES' => '🇪🇸', 'NL' => '🇳🇱', 'BE' => '🇧🇪', 'CH' => '🇨🇭', 'AT' => '🇦🇹',
        'SE' => '🇸🇪', 'NO' => '🇳🇴', 'DK' => '🇩🇰', 'FI' => '🇫🇮', 'IE' => '🇮🇪', 'PT' => '🇵🇹',
        'PL' => '🇵🇱', 'CZ' => '🇨🇿', 'HU' => '🇭🇺', 'SK' => '🇸🇰', 'SI' => '🇸🇮', 'HR' => '🇭🇷',
        'RO' => '🇷🇴', 'BG' => '🇧🇬', 'GR' => '🇬🇷', 'CY' => '🇨🇾', 'MT' => '🇲🇹', 'LU' => '🇱🇺',
        'EE' => '🇪🇪', 'LV' => '🇱🇻', 'LT' => '🇱🇹', 'JP' => '🇯🇵', 'KR' => '🇰🇷', 'CN' => '🇨🇳',
        'IN' => '🇮🇳', 'BR' => '🇧🇷', 'MX' => '🇲🇽', 'AR' => '🇦🇷', 'CL' => '🇨🇱', 'CO' => '🇨🇴',
        'PE' => '🇵🇪', 'VE' => '🇻🇪', 'EC' => '🇪🇨', 'UY' => '🇺🇾', 'PY' => '🇵🇾', 'BO' => '🇧🇴',
        'RU' => '🇷🇺', 'UA' => '🇺🇦', 'BY' => '🇧🇾', 'MD' => '🇲🇩', 'GE' => '🇬🇪', 'AM' => '🇦🇲',
        'AZ' => '🇦🇿', 'KZ' => '🇰🇿', 'UZ' => '🇺🇿', 'KG' => '🇰🇬', 'TJ' => '🇹🇯', 'TM' => '🇹🇲',
        'MN' => '🇲🇳', 'ZA' => '🇿🇦', 'EG' => '🇪🇬', 'MA' => '🇲🇦', 'DZ' => '🇩🇿', 'TN' => '🇹🇳',
        'LY' => '🇱🇾', 'SD' => '🇸🇩', 'ET' => '🇪🇹', 'KE' => '🇰🇪', 'UG' => '🇺🇬', 'TZ' => '🇹🇿',
        'RW' => '🇷🇼', 'BI' => '🇧🇮', 'MW' => '🇲🇼', 'ZM' => '🇿🇲', 'ZW' => '🇿🇼', 'BW' => '🇧🇼',
        'NA' => '🇳🇦', 'SZ' => '🇸🇿', 'LS' => '🇱🇸', 'MZ' => '🇲🇿', 'MG' => '🇲🇬', 'MU' => '🇲🇺',
        'SC' => '🇸🇨', 'TH' => '🇹🇭', 'VN' => '🇻🇳', 'MY' => '🇲🇾', 'SG' => '🇸🇬', 'ID' => '🇮🇩',
        'PH' => '🇵🇭', 'LA' => '🇱🇦', 'KH' => '🇰🇭', 'MM' => '🇲🇲', 'BD' => '🇧🇩', 'LK' => '🇱🇰',
        'MV' => '🇲🇻', 'NP' => '🇳🇵', 'BT' => '🇧🇹', 'PK' => '🇵🇰', 'AF' => '🇦🇫', 'IR' => '🇮🇷',
        'IQ' => '🇮🇶', 'SY' => '🇸🇾', 'JO' => '🇯🇴', 'LB' => '🇱🇧', 'IL' => '🇮🇱', 'PS' => '🇵🇸',
        'SA' => '🇸🇦', 'AE' => '🇦🇪', 'OM' => '🇴🇲', 'YE' => '🇾🇪', 'QA' => '🇶🇦', 'BH' => '🇧🇭',
        'KW' => '🇰🇼', 'TR' => '🇹🇷', 'NZ' => '🇳🇿', 'FJ' => '🇫🇯', 'PG' => '🇵🇬', 'SB' => '🇸🇧',
        'VU' => '🇻🇺', 'NC' => '🇳🇨', 'PF' => '🇵🇫', 'WS' => '🇼🇸', 'KI' => '🇰🇮', 'TO' => '🇹🇴',
        'MH' => '🇲🇭', 'FM' => '🇫🇲', 'PW' => '🇵🇼', 'NR' => '🇳🇷', 'TV' => '🇹🇻'
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('country_name', [$this, 'getCountryName']),
            new TwigFilter('country_flag', [$this, 'getCountryFlag']),
        ];
    }

    /**
     * Get country name from country code
     */
    public function getCountryName(?string $countryCode): string
    {
        if (!$countryCode) {
            return 'Unknown';
        }

        $code = strtoupper(trim($countryCode));
        return $this->countries[$code] ?? 'Unknown';
    }

    /**
     * Get flag emoji from country code
     */
    public function getCountryFlag(?string $countryCode): string
    {
        if (!$countryCode) {
            return '🌍'; // World emoji as fallback
        }

        $code = strtoupper(trim($countryCode));
        return $this->flags[$code] ?? '🌍';
    }
}