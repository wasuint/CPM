## What does this change?

<!-- A short description of the change and the problem it solves. -->

## Why?

<!-- The motivation. Link the issue this closes: Fixes #123 -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (existing behaviour or on-disk format changes)
- [ ] Documentation only
- [ ] Build, CI or tooling

## How was this verified?

<!-- The commands you ran and what you observed. Include output where useful. -->

```
vendor/bin/phpunit
```

## Checklist

- [ ] The test suite passes locally (`vendor/bin/phpunit`).
- [ ] New or changed behaviour is covered by tests, or I explained below why it is not.
- [ ] Code follows PSR-12 and every new file declares `strict_types=1`.
- [ ] Public methods have docblocks with parameter and return documentation.
- [ ] Documentation under `docs/` and the README are updated where behaviour changed.
- [ ] An entry was added to the `## [Unreleased]` section of `CHANGELOG.md`.
- [ ] No secrets, tokens, personal paths or private data are included in the diff.

## Notes for reviewers

<!-- Anything you want a reviewer to look at closely, or known limitations. -->
