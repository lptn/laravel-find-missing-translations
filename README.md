[![Latest Version on Packagist](https://img.shields.io/packagist/v/diglabby/laravel-find-missing-translations.svg?style=flat-square)](https://packagist.org/packages/diglabby/laravel-find-missing-translations)
[![Total Downloads](https://img.shields.io/packagist/dt/diglabby/laravel-find-missing-translations.svg?style=flat-square)](https://packagist.org/packages/diglabby/laravel-find-missing-translations)
[![Test](https://github.com/diglabby/laravel-find-missing-translations/actions/workflows/test.yml/badge.svg)](https://github.com/diglabby/laravel-find-missing-translations/actions/workflows/test.yml)
[![Type coverage](https://shepherd.dev/github/diglabby/laravel-find-missing-translations/coverage.svg)](https://shepherd.dev/github/diglabby/laravel-find-missing-translations)
[![Psalm level](https://shepherd.dev/github/diglabby/laravel-find-missing-translations/level.svg)](https://shepherd.dev/github/diglabby/laravel-find-missing-translations)


# Find missing Laravel Translations

Artisan command to find missing translations.
It takes a basic locale and finds missing keys/translations in other locales.

<p align="center"><img src="https://user-images.githubusercontent.com/5278175/83045008-a9ce0a80-a04d-11ea-89db-90e709ca7b0d.png" alt="Package logo" width="150"></p>

Output example:
<p align="center"><img src="https://i.imgur.com/0vjOwfq.gif" alt="Output example" width="500"></p>

## Installation
```sh
composer require diglabby/laravel-find-missing-translations --dev
```

The package requires PHP 8.3 or higher and Laravel 12 or 13. For Laravel 10 or 11 use `^1.6`.

## Usage
Use the application locale as base and the application lang path (`lang/` since Laravel 9, `resources/lang/` before that):
```sh
php artisan translations:missing
```

The command exits with code `1` when a locale is missing a key or a whole translation file, so it can gate a CI build.

You can specify a base locale:
```sh
php artisan translations:missing --base=es
```

You can specify a list of locales to check:
```sh
php artisan translations:missing --base=es --only=be,en
```

You can specify a list of locales to exclude:
```sh
php artisan translations:missing --base=es --exclude=fr,de
```

You can specify a relative or absolute path to the `lang` directory location:
```sh
php artisan translations:missing --dir=/my-custom-lang-dirname
```

The `lang/vendor` directory, where package translation overrides live, is skipped.

### What is compared

Both translation formats:

* PHP group files, `lang/{locale}/{group}.php`, including files in nested group directories such as `lang/{locale}/admin/orders.php`.
* JSON string keyed files, `lang/{locale}.json`. They are compared only when the base locale has one, so projects that use PHP groups only see no difference.

## Contributing

### Testing
```sh
composer test
```

## Thanks

Inspired by [VetonMuhaxhiri/Laravel-find-missing-translations](https://github.com/VetonMuhaxhiri/Laravel-find-missing-translations)
