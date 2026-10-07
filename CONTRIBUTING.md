# Contributing

Thanks for helping. A few rules keep KHSEO trustworthy:

1. **No fabricated data**: no invented search volume, rankings, reviews,
   ratings, prices or Google data. If a value is unknown, show `UNKNOWN`.
2. **Security first**: check a capability and a nonce on every action, escape on
   output, and use `$wpdb->prepare()` for SQL.
   - Outbound requests go **only** through `KHSEO\Http\SafeFetcher`; a test fails the build otherwise.
   - Changes to content or settings go through `KHSEO\Governance\ChangeGate`.
3. **SEO checks live in `config/rules.php`**, not scattered across engines.
4. **Every bug fix gets a regression test.**
5. **AI must stay optional**: no core feature may require an API key.

Before opening a pull request, run the checks in [README → Development](README.md#development).
They must all pass: unit tests, PHPCS, PHPStan and the integration test.

By contributing you agree your contribution is licensed under GPL-2.0-or-later.
