# tests/bin

Optional location for a PHPUnit phar (`phpunit-10.phar`) when Composer is not
available:

```bash
curl -sSLo tests/bin/phpunit-10.phar https://phar.phpunit.de/phpunit-10.phar
php tests/bin/phpunit-10.phar -c phpunit.xml
```

The default (and CI) path is Composer: `composer install && composer test`.
Note: some sandboxed/egress-proxied environments block `phar.phpunit.de`; use
Composer/Packagist there.
