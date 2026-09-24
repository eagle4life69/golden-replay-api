# Golden Replay API v0.1.21

## Series Detection Regression Repair

Version 0.1.21 repairs the series-classification regression introduced in v0.1.20.

### Fixed

- Restores the full Golden Replay catalog and series implementation that was unintentionally replaced by a simplified implementation in v0.1.20.
- Restores the episode content `Show:` value as the historical series source of truth.
- Restores canonical series normalization and aliases.
- Restores `Western Podcast`, `Western`, and `Westerns` normalization to the canonical `Westerns` genre.
- Prevents a WordPress category's numeric parent term ID from being used to guess the historical radio series.
- Restores primary and secondary genre handling.
- Restores complete series episode counts and series episode discovery.
- Restores derived year/season browsing behavior.
- Restores the persistent catalog/index cache behavior used by the mature API implementation.
- Preserves partial original-air-date handling introduced before v0.1.20.

### Why this matters

The v0.1.20 series detector could choose a season, collection, or other category instead of the actual radio program. This caused valid Western programs such as The Six Shooter to be absent or misclassified in `/series?genre=westerns` even though their episodes were Western content.

### API contract

No endpoint names are changed. Existing Golden Replay clients continue to use the same `/episode`, `/genres`, `/latest`, `/series`, `/seasons`, and `/episodes` routes.

### Testing

After installing v0.1.21, verify that `/wp-json/golden-replay/v1/series?genre=westerns` includes The Six Shooter and spot-check other Western series before proceeding with additional catalog/import work.
