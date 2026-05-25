# PPL DeepL V3 Requests

Shared TYPO3 12.4 service package for direct DeepL REST requests.

This package owns the HTTP request layer for PPL DeepL V3 extensions and intentionally does not depend on `deeplcom/deepl-php`.

## Features

- Central DeepL auth key and API base URL configuration.
- Direct TYPO3 HTTP client integration for DeepL REST calls.
- Shared V3 language, glossary, style-rule and custom-instruction configuration storage.
- Text translation requests.
- Document upload, polling and download requests.
- DeepL V3 glossary listing.
- DeepL V3 style rule listing.
- DeepL V3 language listing.
- Shared configuration lookup for TYPO3 extension configuration, environment variables and TypoScript fallback values.

## Requirements

- TYPO3 CMS 12.4 LTS
- PHP 8.2 or newer
- A DeepL API key

## Installation

```bash
composer require ppl/ppl-deepl-v3-requests:^12.4
```

This package is usually installed together with a consuming extension such as `ppl/ppl-deepl-v3-translate`.

## Configuration

Set the DeepL auth key in TYPO3 extension configuration (`ppl_deepl_v3_requests.authKey`) or directly in `config/system/settings.php` under `EXTENSIONS.ppl_deepl_v3_requests.authKey`. The public package only ships an empty configuration field; no key value, TypoScript auth key constant or environment-variable fallback is included.

Set `apiBaseUrl` when the default endpoint is not correct for the DeepL account:

- DeepL API Pro: `https://api.deepl.com`
- DeepL API Free: `https://api-free.deepl.com`

Enter only the API host, not a concrete path such as `/v3/languages` or `/v2/translate`. The default value is `https://api.deepl.com`.

Classic DeepL v2 calls used for text translation, document translation and language lists are sent to the matching official DeepL v2 host. V3 glossary and style-rule calls continue to use `apiBaseUrl`.

## Shared V3 Product Configuration

This package owns reusable V3 configuration needed by all extensions that consume the request layer:

- Loaded and approved DeepL languages.
- Loaded and approved DeepL glossaries.
- Loaded and approved DeepL style rules.
- Saved custom-instruction presets and custom-instruction normalization.

The shared runtime files are stored under:

```text
var/ppl_deepl_v3_requests/
```

On first read, the storage services migrate existing files from `var/ppl_deepl_v3_translate/` and the older `var/ppl_deepl/` path where applicable. New writes go only to `var/ppl_deepl_v3_requests/`.

## Usage

Use the services from TYPO3 dependency injection or `GeneralUtility::makeInstance()` in consuming TYPO3 extensions:

- `Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService`
- `Ppl\PplDeeplV3Requests\Service\DeeplApiClientService`
- `Ppl\PplDeeplV3Requests\Service\DeeplLanguageConfigurationService`
- `Ppl\PplDeeplV3Requests\Service\DeeplGlossaryConfigurationService`
- `Ppl\PplDeeplV3Requests\Service\DeeplStyleRuleConfigurationService`
- `Ppl\PplDeeplV3Requests\Service\DeeplCustomInstructionConfigurationService`

The package does not register frontend plugins or backend modules by itself. It is a service dependency for extensions that need DeepL V3 request handling.

## Release

Version `12.4.x` is the TYPO3 12.4 release line.

## License

This extension is released under the GNU General Public License v2.0 or later, the common TYPO3 extension license. See `LICENSE`.
