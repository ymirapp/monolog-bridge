<p align="center">
    <a href="https://ymirapp.com" target="_blank" align="center">
        <img src="https://cdn-std.droplr.net/files/acc_680806/69fc3k" width="280">
    </a>
</p>

# Ymir Monolog Bridge

[![Actions Status](https://github.com/ymirapp/monolog-bridge/workflows/Continuous%20Integration/badge.svg)](https://github.com/ymirapp/monolog-bridge/actions)

The Ymir Monolog Bridge provides integration for [Monolog][2] with [Ymir][1].

## Requirements

* PHP >= 7.2.5
* Monolog >= 2.0

## Installation

Install the Ymir Monolog Bridge in your project using composer:

```console
$ composer require ymirapp/monolog-bridge
```

## Usage

### CloudWatchFormatter

The `CloudWatchFormatter` is optimized for AWS CloudWatch logs. It ensures that log messages are formatted correctly for CloudWatch, sanitizing newlines and moving exceptions to the root of the log record for better visibility.

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Ymir\Bridge\Monolog\Formatter\CloudWatchFormatter;

$logger = new Logger('default');
$handler = new StreamHandler('php://stderr');
$handler->setFormatter(new CloudWatchFormatter());

$logger->pushHandler($handler);

$logger->info('Hello, CloudWatch!');
```

## Contributing

Install dependencies using composer:

```console
$ composer install
```

## Links

* [Documentation][3]

[1]: https://ymirapp.com
[2]: https://github.com/Seldaek/monolog
[3]: https://docs.ymirapp.com
