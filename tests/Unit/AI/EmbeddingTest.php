<?php

declare(strict_types=1);

use App\Modules\AI\DTO\Embedding;

it('formats a pgvector literal', function (): void {
    $embedding = new Embedding([0.5, -0.25, 1.0, 0.0], 'bge-m3');

    expect($embedding->toPgVector())->toBe('[0.5,-0.25,1,0]')
        ->and($embedding->dimensions())->toBe(4);
});

it('keeps tiny values in a notation pgvector can parse', function (): void {
    expect((new Embedding([1.0E-7], 'm'))->toPgVector())->toBe('[1.0E-7]');
});
