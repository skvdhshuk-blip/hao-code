<?php

namespace HaoCode\Tools;

/**
 * 工具执行的唯一终态。
 *
 * @internal
 */
enum ToolOutcome: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Aborted = 'aborted';
}
