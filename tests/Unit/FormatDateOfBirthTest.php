<?php

use Illuminate\Support\Carbon;
use Dashed\DashedEcommercePaynl\Classes\PayNL;

it('reformats a stored Y-m-d date to the PayNL d-m-Y format', function () {
    expect(PayNL::formatDateOfBirth('2000-01-15'))->toBe('15-01-2000');
});

it('formats a Carbon instance to d-m-Y', function () {
    expect(PayNL::formatDateOfBirth(Carbon::create(2000, 1, 15)))->toBe('15-01-2000');
});

it('returns null for an empty string so the dob key is omitted', function () {
    expect(PayNL::formatDateOfBirth(''))->toBeNull();
});

it('returns null for null', function () {
    expect(PayNL::formatDateOfBirth(null))->toBeNull();
});

it('returns null for an unparseable value instead of throwing', function () {
    expect(PayNL::formatDateOfBirth('not-a-date'))->toBeNull();
});

it('returns null for the MySQL zero date', function () {
    expect(PayNL::formatDateOfBirth('0000-00-00'))->toBeNull();
});

it('returns null for a future date of birth', function () {
    expect(PayNL::formatDateOfBirth(Carbon::now()->addYear()))->toBeNull();
});
