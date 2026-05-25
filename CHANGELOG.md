# Changelog

## 12.4.1

- Moved shared V3 language, glossary, style-rule and custom-instruction configuration storage into the request package.
- Added migration from `var/ppl_deepl_v3_translate/` and legacy `var/ppl_deepl/` storage where applicable.
- Kept DeepL classic v2 endpoint routing for text, document and language requests.

## 12.4.0

- Release for TYPO3 12.4 LTS.
- Adds shared DeepL REST request services.
- Adds configuration lookup for auth key and API base URL.
- Supports text translation, document translation, language listing, glossary listing and style rule listing.
