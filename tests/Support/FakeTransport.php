<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests\Support;

use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Transport\TransportRequest;
use SelcomDevelopers\Selcom\Transport\TransportResponse;

/**
 * A scripted transport: each send takes the next queued response or exception.
 */
final class FakeTransport implements Transport
{
    /** @var list<TransportRequest> */
    public array $sent = [];

    /** @var \Closure(): void|null Called after every send, for example to advance a fixed clock */
    public ?\Closure $afterSend = null;

    /** @var list<TransportResponse|TransportException> */
    private array $queue = [];

    public function queue(TransportResponse|TransportException $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->sent[] = $request;
        if ($this->afterSend !== null) {
            ($this->afterSend)();
        }
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeTransport has nothing queued.');
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }
}
