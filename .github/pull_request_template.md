## Summary

Explain the issue being solved and the smallest relevant change.

## Verification

- [ ] `composer validate --strict --no-check-publish`
- [ ] `composer test`
- [ ] `composer test:reference`
- [ ] `composer analyse`
- [ ] MariaDB integration tests (if changing transactional behavior)
- [ ] Documentation and examples updated when applicable

## Compatibility and safety

- [ ] Backward compatibility of the public 1.x API considered
- [ ] Concurrency / locking behavior reviewed when relevant
- [ ] No credentials, production data, or unrelated changes included

## Additional notes

Describe limitations, trade-offs, or checks that could not be run.
