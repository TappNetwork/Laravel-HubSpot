<?php

use Carbon\Carbon;
use Tapp\LaravelHubspot\Services\PropertyConverter;

enum TestStringBackedEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum TestIntBackedEnum: int
{
    case Low = 1;
    case High = 10;
}

enum TestUnitEnum
{
    case Pending;
    case Complete;
}

test('converts string-backed enum to its value', function () {
    $result = PropertyConverter::convertValueForHubspot(TestStringBackedEnum::Active, 'status');

    expect($result)->toBe('active');
});

test('converts int-backed enum to string of its value', function () {
    $result = PropertyConverter::convertValueForHubspot(TestIntBackedEnum::High, 'priority');

    expect($result)->toBe('10');
});

test('converts unit enum to its name', function () {
    $result = PropertyConverter::convertValueForHubspot(TestUnitEnum::Pending, 'state');

    expect($result)->toBe('Pending');
});

test('converts null to null', function () {
    $result = PropertyConverter::convertValueForHubspot(null, 'field');

    expect($result)->toBeNull();
});

test('converts Carbon instance to ISO string', function () {
    $date = Carbon::create(2026, 3, 13, 12, 0, 0, 'UTC');
    $result = PropertyConverter::convertValueForHubspot($date, 'date');

    expect($result)->toBe($date->toISOString());
});

test('converts boolean true to string true', function () {
    $result = PropertyConverter::convertValueForHubspot(true, 'flag');

    expect($result)->toBe('true');
});

test('converts boolean false to string false', function () {
    $result = PropertyConverter::convertValueForHubspot(false, 'flag');

    expect($result)->toBe('false');
});

test('converts numeric value to string', function () {
    $result = PropertyConverter::convertValueForHubspot(42, 'count');

    expect($result)->toBe('42');
});

test('converts indexed array to comma-separated string', function () {
    $result = PropertyConverter::convertValueForHubspot(['a', 'b', 'c'], 'tags');

    expect($result)->toBe('a, b, c');
});

test('converts empty array to null', function () {
    $result = PropertyConverter::convertValueForHubspot([], 'tags');

    expect($result)->toBeNull();
});

test('converts associative array with en key to its value', function () {
    $result = PropertyConverter::convertValueForHubspot(['en' => 'Hello', 'fr' => 'Bonjour'], 'greeting');

    expect($result)->toBe('Hello');
});

test('throws for object without toString or toArray', function () {
    $obj = new stdClass;

    PropertyConverter::convertValueForHubspot($obj, 'bad_field');
})->throws(InvalidArgumentException::class);

test('converts plain string as-is', function () {
    $result = PropertyConverter::convertValueForHubspot('hello', 'name');

    expect($result)->toBe('hello');
});
