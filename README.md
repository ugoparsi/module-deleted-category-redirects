# Deleted Category Redirects for Maho

Redirect an old category URL to the closest available parent in the same store view. The setting starts off.

## Install the preview

The package requires PHP 8.5.1 or later. The Composer installation and HTTP tests passed on Maho 26.11.3 with SQLite and on Maho 27.1 development commit `2de25e973` with MariaDB 12.3.4.

From your Maho Composer project, register the GitHub repository and install the preview.

```bash
composer config repositories.deleted-category-redirects vcs https://github.com/ugoparsi/module-deleted-category-redirects
composer require ugoparsi/module-deleted-category-redirects:dev-main
./maho migrate
```

For a Maho source checkout, set `COMPOSER_ROOT_VERSION` on the Composer require command. Use `26.11.3` for that tag or `27.1.x-dev` for the development branch tested here.

## Enable redirects

Go to **System > Configuration > Catalog > Catalog > Search Engine Optimizations**, select the store view and enable **Redirect Deleted Categories to a Parent**.

When a category is deleted, Maho records the existing category paths of its subtree in enabled store views. On a later request that reaches the standard 404, the module resolves the closest available ancestor and uses its current canonical path. When no ancestor is available, the 404 stays in place.

A new category, a CMS page or a merchant rewrite can reuse a recorded path and takes priority while it exists. If that content disappears and the path reaches the standard 404 again, the previous category redirect can apply again. Deleting a replacement category with the setting on records its own ancestors for the path. The native 410 response continues to take priority.

The module records canonical category paths from deletions made while it is enabled. History stays in the store until it is replaced by a later category deletion or the store view is removed.

## Test

Install the package in an isolated Maho source checkout. The tests create their own categories, CMS page and store view. Set the port to match the checkout's base URL. This example uses `http://localhost:8939/`.

```bash
MAHO_BROWSER_PORT=8939 vendor/bin/pest vendor/ugoparsi/module-deleted-category-redirects/tests/Integration/DeletedCategoryFallbackTest.php
```

## License

PHP source and tests use [OSL-3.0](LICENSE.txt). XML configuration uses [AFL-3.0](LICENSE_AFL.txt).
