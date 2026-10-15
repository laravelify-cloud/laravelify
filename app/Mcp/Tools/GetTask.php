<?php

namespace App\Mcp\Tools;

use App\Models\PanelTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The status of a background task (a database import, for example): queued, running, success, or failed, with its progress and message. Call it every 10–20 seconds until finished is true. A task that stays queued means the queue worker is not running on the server.')]
class GetTask extends Tool
{
    public function handle(Request $request): Response
    {
        $task = PanelTask::query()->find((int) $request->get('task_id'))
            ?? throw ValidationException::withMessages(['task_id' => 'No such task.']);

        return Response::json([
            'task_id' => $task->id,
            'label' => $task->label,
            'status' => $task->status,
            'progress' => $task->progress,
            'message' => $task->message,
            'finished' => $task->isFinished(),
            'waiting_too_long' => $task->isStuck(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task id from the tool that started it.')->required(),
        ];
    }
}
