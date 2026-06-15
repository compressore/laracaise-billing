# Contributing

Contributions are welcome and will be fully credited.

## Etiquette

- Be welcoming to newcomers and encourage diverse new contributors.
- Discuss major changes in an issue first before submitting a pull request.
- Write tests for your changes.
- Document every public-facing method or class.
- Respect the code style enforced by Pint (`composer format`).

## Getting Started

1. Fork and clone the repository.
2. Run `composer install`.
3. Run `composer test` — all tests must pass before you start.
4. Create a branch: `git checkout -b feature/your-feature`.
5. Make your changes.
6. Run the full check suite:
   ```bash
   composer format
   composer analyse
   composer test
   ```
7. Commit, push, and open a pull request against `main`.

## Pull Request Guidelines

- Target the `main` branch.
- Add an entry to `CHANGELOG.md` under `[Unreleased]`.
- Keep PRs focused — one feature or fix per PR.

## Security Vulnerabilities

Please email christian@twomenandatruck.co.za rather than opening a public issue.
