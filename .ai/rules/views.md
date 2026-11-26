---
paths:
  - 'resources/views/**'
---

# Views

## Keep the panels' translations up to date
Text in the panels' views goes through `{{ __('English text', ['name' => $value]) }}` with a literal key. Add or update its Dutch translation in `lang/nl.json` in the same change and remove translations of text that goes; `tests/Feature/TranslationsTest.php` enforces it. Mail and text posted to pull requests stay English.
