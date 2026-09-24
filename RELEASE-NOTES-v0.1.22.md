# Golden Replay API v0.1.22

## Scheduled episode support

- Includes WordPress scheduled/future episodes in series discovery and catalog indexes.
- Includes scheduled episodes in season/year browsing and `/episodes`.
- Scheduled episode payloads report `availability.status` as `scheduled`, `available` as `false`, and expose the scheduled publication time in `scheduled_for`.
- Direct episode lookup supports both published and scheduled episodes.
- `/latest` intentionally remains published-only so it always returns a playable episode.
