<?php

declare(strict_types=1);

use App\Modules\Generation\Support\ChunkBuffer;

/** Керований годинник: тест сам вирішує, скільки мілісекунд «минуло». */
function bufferWithClock(float &$now, int $interval = 100, int $maxChars = 1000): ChunkBuffer
{
    return new ChunkBuffer($interval, $maxChars, static function () use (&$now): float {
        return $now;
    });
}

it('sends the very first delta immediately for a fast first token', function (): void {
    $now = 0.0;
    $buffer = bufferWithClock($now);

    expect($buffer->push('При'))->toBe(['При']);
});

it('batches deltas within the interval and releases them once it has passed', function (): void {
    $now = 0.0;
    $buffer = bufferWithClock($now);
    $buffer->push('a');

    $now = 30.0;
    expect($buffer->push('b'))->toBe([]);
    $now = 60.0;
    expect($buffer->push('c'))->toBe([]);
    $now = 100.0;
    expect($buffer->push('d'))->toBe(['bcd']);
});

it('flushes the remainder at the end of the stream', function (): void {
    $now = 0.0;
    $buffer = bufferWithClock($now);
    $buffer->push('a');
    $buffer->push('b');

    expect($buffer->flush())->toBe(['b'])
        ->and($buffer->flush())->toBe([]);
});

it('splits a large batch into messages no longer than the limit, without breaking multibyte chars', function (): void {
    $now = 0.0;
    $buffer = bufferWithClock($now, maxChars: 4);

    expect($buffer->push('привітання'))->toBe(['прив', 'ітан', 'ня']);
});

it('releases early when the buffer reaches the size limit', function (): void {
    $now = 0.0;
    $buffer = bufferWithClock($now, maxChars: 5);
    $buffer->push('a');

    expect($buffer->push('bcdef'))->toBe(['bcdef']);
});

it('survives a misconfigured zero chunk size', function (): void {
    $now = 0.0;

    expect(bufferWithClock($now, maxChars: 0)->push('ab'))->toBe(['a', 'b']);
});
