# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode data for the **Golden Replay** application.

Golden Replay is designed to consume episode information from WordPress without exposing unnecessary WordPress internals or requiring the mobile application to parse rendered post content itself.

## Current Version

**0.1.0**

## Current API Endpoint

```text
/wp-json/golden-replay/v1/episode/{id}
```

Example using WordPress post ID `21571`:

```text
https://www.otrwesterns.com/wp-json/golden-replay/v1/episode/21571
```

The endpoint currently returns data only for published WordPress posts.

## Returned Episode Data

The API builds an explicit, whitelisted response containing information such as:

- WordPress post ID
- Stable WordPress URL (`?p=ID`)
- Current pretty URL
- Episode title
- Series information
- Publisher/feed information
- Genre
- Original historical air date
- WordPress publication and modification dates
- Episode description
- Duration in seconds and display format
- File size in bytes and display format
- Spreaker provider, episode ID, and media URL
- Structured credits such as stars, guests, writers, producers, directors, music, announcers, and narrators
- Availability information

Fields that cannot be reliably determined are returned as `null` or an empty collection rather than fabricated.

## Audio / Spreaker

Golden Replay reads the WordPress `enclosure` post metadata server-side. When a valid Spreaker enclosure is present, the plugin extracts the Spreaker episode ID and media URL.

Spreaker URLs are validated and currently restricted to the `api.spreaker.com` host. The application therefore receives the existing Spreaker-hosted media URL instead of constructing an arbitrary remote URL.

This is important because Spreaker remains the audio distribution provider for these episodes.

## Security Design

The public API is intentionally read-only and narrowly scoped.

Current protections include:

- Only published WordPress posts are returned.
- Episode IDs are validated as positive integers.
- Responses are constructed from an explicit whitelist of fields.
- Raw WordPress post metadata is not exposed.
- No create, edit, delete, upload, or execution endpoints are provided.
- No database credentials, API keys, access tokens, or other secrets are stored in the plugin.
- The client cannot provide an arbitrary URL for the server to retrieve.
- Spreaker media URLs found in enclosure metadata are validated before being returned.
- Errors use generic messages rather than exposing filesystem paths, SQL, stack traces, or server internals.

The repository is public by design. Security must not depend on hiding the source code.

## WordPress Installation

1. Download the ZIP from a published GitHub Release.
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**.
3. Upload the Golden Replay API ZIP.
4. Install and activate the plugin.
5. Test a known published episode using the REST endpoint.

## Automatic Updates

The plugin includes a native GitHub release updater in `github-updater.php`.

WordPress checks the latest published release from this repository and compares its version with the installed plugin version. When a newer version is available, it can appear in the normal WordPress Plugins update interface.

The updater caches the latest-release check for approximately 15 minutes to avoid unnecessary GitHub requests.

### Important Release Requirement

A GitHub **Release** must be published for WordPress to discover a new version. Merely committing code to `main` does not publish a WordPress plugin update.

The release tag should correspond to the plugin version, for example:

```text
v0.1.0
v0.1.1
v0.2.0
```

The version in `golden-replay-api.php` must also be updated when a new plugin version is released.

## Development Workflow

The `main` branch represents release-ready code and should remain protected.

Normal development workflow:

```text
Create a temporary feature/fix branch
        ↓
Make and test changes
        ↓
Open a Pull Request into main
        ↓
Review and merge
        ↓
Update/version the plugin as appropriate
        ↓
Publish a GitHub Release
        ↓
WordPress detects the newer release
```

Temporary development branches can be deleted after their pull requests are merged.

A permanent `develop` branch is not currently required.

## Project Structure

```text
golden-replay-api/
├── golden-replay-api.php   # Main plugin and REST API
├── github-updater.php      # GitHub Release based WordPress updater
└── README.md               # Project documentation
```

## Current Development Status

Version 0.1.0 is the initial API implementation. The first goal is to validate the normalized JSON contract against real OTRWesterns.com episode data before expanding the API.

Planned future work may include additional episode/list endpoints, series browsing, genres, years, search, scheduled-content handling, artwork, and other data needed by the Golden Replay application.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
