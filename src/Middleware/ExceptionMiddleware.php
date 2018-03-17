<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class ExceptionMiddleware implements Middleware, ServerObserver {
    /** @var bool */
    private $debug = false;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise {
        return call(function () use ($request, $requestHandler) {
            static $internalErrorHtml = null;

            if ($internalErrorHtml === null) {
                $internalErrorHtml = \file_get_contents(\dirname(__DIR__, 2) . "/resources/internal-server-error.html");
            }

            try {
                return yield $requestHandler->handleRequest($request);
            } catch (\Throwable $exception) {
                $status = Status::INTERNAL_SERVER_ERROR;

                // Return an HTML page with the exception in debug mode.
                if ($this->debug) {
                    $html = \str_replace(
                        ["{uri}", "{class}", "{message}", "{file}", "{line}", "{trace}"],
                        \array_map("htmlspecialchars", [
                            $request->getUri(),
                            \get_class($exception),
                            $exception->getMessage(),
                            $exception->getFile(),
                            $exception->getLine(),
                            $exception->getTraceAsString()
                        ]),
                        $internalErrorHtml
                    );

                    return new Response($status, [
                        "content-type" => "text/html; charset=utf-8",
                    ], $html);
                }

                return yield $this->errorHandler->handle($status, null, $request);
            }
        });
    }

    public function onStart(Server $server): Promise {
        $this->debug = $server->getOptions()->isInDebugMode();
        $this->errorHandler = $server->getErrorHandler();
        return new Success;
    }

    public function onStop(Server $server): Promise {
        return new Success;
    }
}