<?php

namespace Ontob\Moonwatch\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Ontob\Moonwatch\Reporter;

/**
 * The handler behind the "moonwatch" log channel. Records at or above the
 * channel's level are collected and sent when the request or job ends.
 */
class MoonwatchHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected Reporter $reporter,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $this->reporter->reportRecord($record);
    }
}
