<?php

declare(strict_types=1);

namespace HaoCode\Contracts;

/**
 * Why an SDK invocation stopped.
 *
 * @api
 */
enum RunTerminationReason: string
{
    case Normal = 'normal';
    case Cancelled = 'cancelled';
    case BudgetExhausted = 'budget_exhausted';
    case TurnLimit = 'turn_limit';
    case RepeatedToolFailure = 'repeated_tool_failure';

    /** A plan is ready for the host to review; the run stopped instead of implementing it. */
    case PlanReady = 'plan_ready';
}
