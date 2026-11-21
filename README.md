# Deleted Category Redirects for Maho

Redirect an old category URL to the closest available parent in the same store view. The setting starts off.

## Install

The package targets the Maho 27.1 series and requires PHP 8.5.1 or later. The local preview was validated on the 27.1 development branch. A stable 27.1 release is pending.

For a local preview, place this package in `../category-redirect-package` relative to the store project and run from the project:

```bash
composer config repositories.deleted-category-redirects '{"type":"path","url":"../category-redirect-package","options":{"symlink":false,"versions":{"ugoparsi/module-deleted-category-redirects":"0.1.0"}}}'
composer require ugoparsi/module-deleted-category-redirects:^0.1
./maho migrate
```

For a Maho 27.1 source checkout, set `COMPOSER_ROOT_VERSION=27.1.x-dev` on the Composer require command.

## Enable redirects

Go to **System > Configuration > Catalog > Catalog > Search Engine Optimizations**, select the store view and enable **Redirect Deleted Categories to a Parent**.

When a category is deleted, Maho records the existing category paths of its subtree in enabled store views. On a later request that reaches the standard 404, the module resolves the closest available ancestor and uses its current canonical path. When no ancestor is available, the 404 stays in place.

A new category, a CMS page or a merchant rewrite can reuse a recorded path and takes priority while it exists. If that content disappears and the path reaches the standard 404 again, the previous category redirect can apply again. Deleting a replacement category with the setting on records its own ancestors for the path. The native 410 response continues to take priority.

The module records canonical category paths from deletions made while it is enabled. History stays in the store until it is replaced by a later category deletion or the store view is removed.

## Test

Install the package in an isolated Maho 27.1 source checkout. The tests create their own categories, CMS page and store view. For a checkout configured at `http://localhost:8929/`, run:

```bash
MAHO_BROWSER_PORT=8929 vendor/bin/pest vendor/ugoparsi/module-deleted-category-redirects/tests/Integration/DeletedCategoryFallbackTest.php
```

## License

PHP source and tests use [OSL-3.0](LICENSE.txt). XML configuration uses [AFL-3.0](LICENSE_AFL.txt).
