<?php

namespace HaoCode\Providers;

use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileEdit\FileEditTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\Glob\GlobTool;
use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Tools\TodoWrite\TodoWriteTool;
use HaoCode\Tools\AskUserQuestion\AskUserQuestionTool;
use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\WebSearch\WebSearchTool;
use HaoCode\Tools\PlanMode\EnterPlanModeTool;
use HaoCode\Tools\PlanMode\ExitPlanModeTool;
use HaoCode\Tools\Lsp\LspTool;
use HaoCode\Tools\Agent\AgentTool;
use HaoCode\Tools\Agent\SendMessageTool;
use HaoCode\Tools\Skill\SkillTool;
use HaoCode\Tools\Notebook\NotebookEditTool;
use HaoCode\Tools\Config\ConfigTool;
use HaoCode\Tools\Cron\CronCreateTool;
use HaoCode\Tools\Cron\CronDeleteTool;
use HaoCode\Tools\Cron\CronListTool;
use HaoCode\Tools\Worktree\EnterWorktreeTool;
use HaoCode\Tools\Worktree\ExitWorktreeTool;
use HaoCode\Tools\Task\TaskCreateTool;
use HaoCode\Tools\Task\TaskGetTool;
use HaoCode\Tools\Task\TaskListTool;
use HaoCode\Tools\Task\TaskUpdateTool;
use HaoCode\Tools\Task\TaskStopTool;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\Sleep\SleepTool;
use HaoCode\Tools\Team\TeamCreateTool;
use HaoCode\Tools\Team\TeamDeleteTool;
use HaoCode\Tools\Team\TeamListTool;
use HaoCode\Tools\ToolSearch\ToolSearchTool;
use Illuminate\Support\ServiceProvider;

class ToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry();

            // Core tools
            $registry->register($app->make(BashTool::class));
            $registry->register($app->make(FileReadTool::class));
            $registry->register($app->make(FileEditTool::class));
            $registry->register($app->make(FileWriteTool::class));
            $registry->register($app->make(GlobTool::class));
            $registry->register($app->make(GrepTool::class));
            $registry->register($app->make(TodoWriteTool::class));
            $registry->register($app->make(AskUserQuestionTool::class));

            // Web tools
            $registry->register($app->make(WebFetchTool::class));
            $registry->register($app->make(WebSearchTool::class));

            // Agent tools
            $registry->register($app->make(AgentTool::class));
            $registry->register($app->make(SendMessageTool::class));
            $registry->register($app->make(SkillTool::class));

            // Code intelligence
            $registry->register($app->make(LspTool::class));

            // Notebook editing
            $registry->register($app->make(NotebookEditTool::class));

            // Plan mode
            $registry->register($app->make(EnterPlanModeTool::class));
            $registry->register($app->make(ExitPlanModeTool::class));

            // Configuration
            $registry->register($app->make(ConfigTool::class));

            // Scheduled tasks
            $registry->register($app->make(CronCreateTool::class));
            $registry->register($app->make(CronDeleteTool::class));
            $registry->register($app->make(CronListTool::class));

            // Worktree
            $registry->register($app->make(EnterWorktreeTool::class));
            $registry->register($app->make(ExitWorktreeTool::class));

            // Task management
            $registry->register($app->make(TaskCreateTool::class));
            $registry->register($app->make(TaskGetTool::class));
            $registry->register($app->make(TaskListTool::class));
            $registry->register($app->make(TaskUpdateTool::class));
            $registry->register($app->make(TaskStopTool::class));

            // Team tools
            $registry->register($app->make(TeamCreateTool::class));
            $registry->register($app->make(TeamListTool::class));
            $registry->register($app->make(TeamDeleteTool::class));

            // Tool search — registered lazily via connectMcpServers() only when MCP tools exist

            // MCP resources
            $registry->register($app->make(ListMcpResourcesTool::class));
            $registry->register($app->make(ReadMcpResourceTool::class));

            // Utility
            $registry->register($app->make(SleepTool::class));

            return $registry;
        });
    }
}
