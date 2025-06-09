# Running the PHPUnit tests

These tests cover some of the core functionality of the MLSImport plugin.

## Requirements

* PHP 7.4 or later
* [Composer](https://getcomposer.org/) installed globally
* `phpunit` available under `vendor/bin/phpunit`. You can install it via Composer:

```bash
composer require --dev phpunit/phpunit ^9
```

## Executing the tests

1. Install development dependencies (including PHPUnit) if you haven't already:

   ```bash
   composer install
   ```

2. Run PHPUnit from the project root:

   ```bash
   vendor/bin/phpunit
   ```

The configuration file `phpunit.xml` will bootstrap the test environment and run the test suite located in the `tests/` directory.
