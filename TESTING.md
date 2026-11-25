# Testing

## Requirements

You'll need:

- PHP 7.4 minimum.
- docker, for the database.
- symfony CLI, to run the local server.
- composer, to install PHP dependencies.
- npm and yarn, to install ui dependencies and build the JS/CSS files.

## Installation

```bash
make install
```

This will run a Sylius app (the one in `tests/Application/`) with the plugin
installed and all Sylius' sample data. It uses the symfony binary.

## Usage

### List all available commands

```bash
make help
```

### Running minimum plugin tests

- PHPUnit

    ```bash
    make test.phpunit
    ```

- PHP CS fixer

    ```bash
    make test.phpcs
    ```

    > Tip: You can fix your code with `make test.phpcs.fix`!

- PHPSpec

    ```bash
    make test.phpspec
    ```

- PHPStan

    ```bash
    make test.phpstan
    ```

> Tip: You can run all tests with `make test.all`!

### Homepage switch integration tests

Use a disposable **MariaDB** database named with a `_qa` or `_test` suffix. The
suite writes fixtures and Messenger messages and leaves them in the database.
It is not part of `make test.phpunit` or `make test.all`.

Use PHP 8.3 and the Sylius 2.1 test application from `make application`.
Before running the suite locally or in CI:

1. Provision a dedicated MariaDB database for this run. Set `DATABASE_URL` to
   that database explicitly, not to a development or client database.
2. Run `doctrine:migrations:migrate --no-interaction` against that database
   from `tests/Application`. Build the test application's admin assets
   (`make yarn.install`) for the BrowserKit scenario. The Homepage migration
   creates its own transport table; no transport setup is needed for the suite.
3. Export the application's test settings (including `APP_SECRET` and
   `MONSIEURBIZ_SYLIUS_MEDIA_MANAGER_PUBLIC_FOLDER`,
   `MONSIEURBIZ_SYLIUS_MEDIA_MANAGER_ROOT_FOLDER_FROM_PUBLIC` and
   `MONSIEURBIZ_SYLIUS_MEDIA_MANAGER_MAX_FILE_SIZE`). The integration target
   loads Composer autoloading directly; it does not load Dotenv files.
4. Run:

   ```bash
   HOMEPAGE_SWITCH_QA=1 make test.integration
   ```

In CI, use a fresh database per job and stop the database service with the job.
Do not run a background worker against the test queue while the suite runs.
If Composer blocks the supported Sylius 2.1 series on security advisories,
keep any exception confined to the ignored test application, keep audit
reporting enabled, and do not expose the test application over HTTP.
