<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

interface WebSocketGateway
{
    public function open(Server $server, Request $request): void;

    public function message(Server $server, Frame $frame): void;

    public function close(Server $server, int $fileDescriptor): void;
}
