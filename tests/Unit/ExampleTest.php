<?php

declare(strict_types=1);

it('runs on PHP 8.4+', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});
