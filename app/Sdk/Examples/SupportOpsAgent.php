<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Tools\ToolResult;

final class SupportOpsAgent
{
    use SupportOpsAgentConstructConcern;
    use SupportOpsAgentRegisterAbortHandlerConcern;

    /** @var callable(string): void */
    private $writer;

    private readonly AbortController $abortController;

    private readonly RunbookNotesTool $runbookNotesTool;

    /** @var list<SdkTool> */
    private readonly array $tools;

    /** @var list<SdkSkill> */
    private readonly array $skills;
}
