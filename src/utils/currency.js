// Maps an ISO 3166-1 alpha-2 country code to that country's real local
// currency (ISO 4217). Used so a teacher/institute's price defaults to
// their own country's currency the moment they set their country — not
// a single "universal" currency imposed on everyone regardless of
// where they actually are. This was a real bug, not a hypothetical:
// every profile previously defaulted to INR (the schema's DEFAULT),
// which is wrong for a teacher anywhere outside India — the same class
// of problem as defaulting everyone to USD.
//
// Deliberately NOT exhaustive of all ~180 ISO countries — covers the
// countries realistically relevant given the platform's actual
// language support (8 languages) and likely early markets. Add more
// as real users show up from a country not yet listed; the fallback
// below handles an unmapped code without crashing.
const COUNTRY_CURRENCY = {
  // South Asia
  IN: 'INR', PK: 'PKR', BD: 'BDT', LK: 'LKR', NP: 'NPR',
  // Middle East / Arabic-speaking
  AE: 'AED', SA: 'SAR', EG: 'EGP', QA: 'QAR', KW: 'KWD', BH: 'BHD',
  OM: 'OMR', JO: 'JOD', LB: 'LBP', IQ: 'IQD', MA: 'MAD', DZ: 'DZD', TN: 'TND',
  // Europe
  GB: 'GBP', FR: 'EUR', DE: 'EUR', ES: 'EUR', IT: 'EUR', PT: 'EUR',
  NL: 'EUR', BE: 'EUR', IE: 'EUR', AT: 'EUR', GR: 'EUR', FI: 'EUR',
  CH: 'CHF', SE: 'SEK', NO: 'NOK', DK: 'DKK', PL: 'PLN', CZ: 'CZK',
  RU: 'RUB', UA: 'UAH', RO: 'RON', HU: 'HUF',
  // Americas
  US: 'USD', CA: 'CAD', MX: 'MXN', BR: 'BRL', AR: 'ARS', CO: 'COP',
  CL: 'CLP', PE: 'PEN',
  // East / Southeast Asia
  CN: 'CNY', JP: 'JPY', KR: 'KRW', TW: 'TWD', HK: 'HKD', SG: 'SGD',
  MY: 'MYR', TH: 'THB', VN: 'VND', PH: 'PHP', ID: 'IDR',
  // Oceania
  AU: 'AUD', NZ: 'NZD',
  // Africa (beyond North Africa above)
  ZA: 'ZAR', NG: 'NGN', KE: 'KES', GH: 'GHS',
};

const FALLBACK_CURRENCY = 'USD'; // only used for a country code not in the map above — not imposed by default

function currencyForCountry(countryCode) {
  if (!countryCode) return null;
  return COUNTRY_CURRENCY[countryCode.toUpperCase()] || FALLBACK_CURRENCY;
}

module.exports = { COUNTRY_CURRENCY, currencyForCountry, FALLBACK_CURRENCY };
