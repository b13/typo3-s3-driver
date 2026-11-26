<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "s3_driver" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\S3Driver\Tests\Unit\Resource;

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for the HTTP layer of the S3 client: records the commands that
 * would be sent and answers them with fixed results, without any network.
 */
final class RecordingHandler
{
    /**
     * @var list<CommandInterface>
     */
    public array $commands = [];

    /**
     * @param array<string, array<string, mixed>> $results Result data per command name
     */
    public function __construct(
        private readonly array $results = [],
    ) {
    }

    public function __invoke(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $this->commands[] = $command;
        return Create::promiseFor(new Result($this->results[$command->getName()] ?? []));
    }

    /**
     * @return list<string>
     */
    public function getCommandNames(): array
    {
        return array_map(static fn (CommandInterface $command): string => $command->getName(), $this->commands);
    }

    public function getLastCommand(string $name): ?CommandInterface
    {
        foreach (array_reverse($this->commands) as $command) {
            if ($command->getName() === $name) {
                return $command;
            }
        }
        return null;
    }
}
