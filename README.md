<div align="center">
    <h1>Email Provider</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://img.shields.io/packagist/v/eudeka/email-provider.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://img.shields.io/packagist/php-v/eudeka/email-provider.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://badge.laravel.cloud/badge/eudeka/email-provider?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/eudeka/email-provider/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/eudeka/email-provider/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://img.shields.io/packagist/dt/eudeka/email-provider.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Eudeka Email Provider

## Installation

You can install the package via Composer:

```bash
composer require eudeka/email-provider
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="email-provider"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="email-provider-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="email-provider-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="email-provider-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="email-provider-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="email-provider-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Email Provider! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Eudeka](https://github.com/eudeka)
- [All Contributors](../../contributors)

## License

Email Provider is open-sourced software licensed under the [MIT license](LICENSE.md).
