# wp-pricelist-automation
WordPress plugin for automating changes to products based on the distributors price lists

## Running Tests

This project uses PHPUnit for automated testing.

### Prerequisites

- PHP 8.3
- Composer

### Install Dependencies

```bash
cd stock-sync
composer install
```

### Run Tests

```bash
cd stock-sync && vendor/bin/phpunit
```

### Run Tests with Coverage

```bash
cd stock-sync && vendor/bin/phpunit --coverage-html tests/coverage --coverage-clover tests/coverage/coverage.xml
```

After running coverage, open `stock-sync/tests/coverage/index.html` in your browser to view the detailed coverage report.

### CI

Tests run automatically on every push and pull request to `main` via GitHub Actions. Coverage artifacts are uploaded for each run.
