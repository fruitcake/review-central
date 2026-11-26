---
paths:
  - 'app/Filament/**'
---

# Filament

## Keep the panels' translations up to date
User-visible text in the panels (labels, headings, notifications, helper texts, enum labels, Blade views) goes through `__()` with the English text as a literal key and `:placeholders` for variable parts; never `__($variable)` or a key glued together. In the same change, add the Dutch translation to `lang/nl.json` (and any other `lang/*.json`), update it when the English changes, and remove it when the text goes. `tests/Feature/TranslationsTest.php` fails on a missing, stale or placeholder-mismatched translation.
