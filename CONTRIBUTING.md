# Contributing

Thank you for considering contributing to this project! Every contribution is welcome and helps improve the quality of the project. To ensure a smooth process and maintain high code quality, please follow the steps below.

## Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/)

## Preparation

```bash
# Clone repository
git clone https://github.com/konradmichalik/typo3-routing.git
cd typo3-routing

# Start the project with DDEV
ddev start

# Install dependencies
ddev composer install
```

## Run linters

```bash
# All linters
ddev cgl lint

# Specific linters
ddev cgl lint:composer
ddev cgl lint:editorconfig
ddev cgl lint:language
ddev cgl lint:php
ddev cgl lint:typoscript
ddev cgl lint:yaml

# Fix all CGL issues
ddev cgl fix

# Fix specific CGL issues
ddev cgl fix:composer
ddev cgl fix:editorconfig
ddev cgl fix:php
```

## Run static code analysis

```bash
# All static code analyzers
ddev cgl sca

# Specific static code analyzers
ddev cgl sca:php
```

## Run tests

```bash
# All tests
ddev composer test

# All tests with code coverage
ddev composer test:coverage
```

## TYPO3 Setup

For testing the extension, you need to set up the TYPO3 instances.

```bash
# Install all TYPO3 versions, which are supported by the extension
ddev install all

# Or install specific TYPO3 versions
ddev install 11
ddev install 12
ddev install 13

# Open the overview page
ddev launch

# Run TYPO3 specific commands
ddev 12 typo3 cache:flush
ddev 13 composer install
ddev all typo3 database:updateschema
```

## Performance benchmark

To check whether attribute routing is meaningfully slower than a conventional middleware, the
`routing_benchmark` fixture extension exposes matched endpoint pairs — one served by
`typo3-routing` (`/api/bench/routing/*`), one by a hand-rolled PSR-15 middleware
(`/api/bench/plain/*`) that returns byte-for-byte identical responses. Server-side timings are
recorded by [`konradmichalik/typo3-request-profiler`][2] (pinned to `dev-main` until the
`TYPO3_REQUEST_PROFILER_FORCE` opt-in is released) and aggregated into a comparison table.

```bash
# Install an instance (profiler + benchmark fixture are pulled in automatically)
ddev install 13

# Run the benchmark (default 50 requests per endpoint)
ddev benchmark            # lowest supported version
ddev benchmark 13 100     # version 13, 100 requests per endpoint
```

Each scenario (no arguments, path parameter, query parameter) is run interleaved after a warmup,
then `timing.total_ms` is reported per variant with the routing-vs-plain delta. The dispatch
overhead is in the order of ~0.1 ms per request — negligible against a full frontend request, so
the large percentage delta is an artifact of the near-zero plain-middleware baseline.

## Submit a pull request

After completing your work, **open a pull request** and provide a description of your changes. Ideally, your PR should reference an issue that explains the problem you are addressing.

All mentioned code quality tools will run automatically on every pull request. For more details, see the relevant [workflows][1].

[1]: .github/workflows
[2]: https://github.com/konradmichalik/typo3-request-profiler
