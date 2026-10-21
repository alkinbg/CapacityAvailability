# Contributing

Thanks for considering a contribution.

The core API must remain framework-agnostic. Persistence integrations are
optional and must document their consistency and transaction guarantees.

Before opening a pull request:

1. Add tests that demonstrate the issue or the behavior being introduced.
2. Run `composer test` and `composer test:reference`.
3. Run `composer analyse`.
4. For database-related changes, run the isolated MariaDB integration tests
   described in [docs/TRANSACTIONS.md](docs/TRANSACTIONS.md).
5. Describe breaking changes, limitations and trade-offs.

Please keep pull requests focused, with clearly justified behavior changes.
