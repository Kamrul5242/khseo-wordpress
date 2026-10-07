# Contributing

Thanks for helping. A few rules keep KHSEO trustworthy:

1. **No fabricated data**: no invented search volume, rankings, reviews,
   ratings, prices or Google data. If a value is unknown, show `UNKNOWN`.
2. **Security first**: check a capability and a nonce on every action, escape on
   output, use `$wpdb->prepare()` for SQL, and send outbound URLs through
   `KHSEO\Security\UrlGuard`.
3. **SEO checks live in `config/rules.php`**, not scattered across engines.
4. **Every bug fix gets a regression test.**
5. **AI must stay optional**: no core feature may require an API key.

Before opening a pull request, run the checks in [README → Development](README.md#development).
They must all pass: unit tests, PHPCS, PHPStan and the integration test.

By contributing you agree your contribution is licensed under GPL-2.0-or-later.
