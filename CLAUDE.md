# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`shoxcie/http-client` — a PHP library wrapping Symfony HttpClient. Namespace: `Shoxcie\HttpClient`.

## Commands

```bash
composer test                # Run Pest tests
composer test -- --filter=X  # Run a single test by name
composer analyse             # PHPStan (level 10, bleeding edge, strict rules)
composer cs:check            # PHP-CS-Fixer dry run (PER-CS 2.0)
composer cs:fix              # Auto-fix code style
composer rector              # Apply Rector refactorings
composer rector:check        # Rector dry run
composer quality             # Run analyse + cs:check + test in sequence
```

## Code Standards

- `declare(strict_types=1)` in every PHP file — enforced by CS Fixer and architecture tests
- All classes must be `final` — enforced by architecture test
- No `dd`, `dump`, `var_dump`, `die`, `exit` — enforced by architecture test
- PER Coding Style 2.0 with risky rules, strict comparisons (`===`), alphabetical imports
- PHPStan level 10 with strict rules and bleeding edge — no baseline, fix all errors
- When adding dependencies, use `composer require package/name` without version constraints — let Composer resolve versions
