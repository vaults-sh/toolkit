<?php

declare(strict_types=1);

use Vaults\Project\ProjectName;

it('normalises free text into a slug', function (string $input, string $expected) {
    expect(ProjectName::normalise($input))->toBe($expected);
})->with([
    ['Checkout API', 'checkout-api'],
    ['checkout_api', 'checkout-api'],
    ['  Checkout--API  ', 'checkout-api'],
    ['-leading-and-trailing-', 'leading-and-trailing'],
    ['My.Site v2', 'mysite-v2'],
    ['ÜBER app', 'ber-app'],
]);

it('validates slugs the way the dashboard does', function (string $input, bool $valid) {
    expect(ProjectName::isValid($input))->toBe($valid);
})->with([
    ['checkout-api', true],
    ['a1', true],
    ['Checkout-API', false],
    ['checkout_api', false],
    ['checkout--api', false],
    ['-checkout', false],
    ['a', false],
    [str_repeat('a', 65), false],
]);

it('suggests a valid name from composer.json or the directory', function () {
    $directory = sys_get_temp_dir().'/vaults-name-'.uniqid().'/Checkout_API';
    mkdir($directory, 0755, true);

    expect(ProjectName::suggest($directory))->toBe('checkout-api');

    file_put_contents($directory.'/composer.json', '{"name":"cranbri/Billing.Service"}');

    expect(ProjectName::suggest($directory))->toBe('billingservice');
});
