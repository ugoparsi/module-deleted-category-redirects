# Release notes

## v0.1.0

The module redirects a deleted category URL to the closest available parent in the same store view. It starts disabled. Enable **Redirect Deleted Categories to a Parent** under **System > Configuration > Catalog > Catalog > Search Engine Optimizations**.

Current categories, CMS pages, merchant rewrites, and native 410 responses take priority. If a reused path later reaches the standard 404, the recorded fallback can apply again. Deleting a replacement category while the setting is enabled records its own ancestors. A path with no available parent keeps the 404.

Requires PHP 8.5.1 or later. Verified on Maho 26.11.3 with SQLite and on Maho 27.1 development commit `2de25e973` with MariaDB 12.3.4. [Installation](README.md#install)
