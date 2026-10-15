<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\DatabaseImporter;
use App\Services\PanelTaskRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[IsDestructive]
#[Description('Import a .sql or .sql.gz dump that is in the website\'s folder into the MySQL database the panel made for it (create-website with create_database). It runs in the background as the site\'s own database user, so it can only change that database; follow it with get-task. With reset true the database is emptied first, which is needed when a deploy already ran the migrations (the dump creates the same tables). Ask the user before reset: it deletes the database\'s current data. Get the dump into the site folder first: upload-file for small ones (up to 20 MB), or the user copies it to the server (scp) for big ones.')]
class ImportDatabase extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, DatabaseImporter $importer, PanelTaskRunner $tasks): Response
    {
        $website = $this->findWebsite($request->get('website'));

        try {
            $input = $importer->prepare($website, (string) $request->get('path'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        $reset = (bool) $request->get('reset', false);
        $task = $tasks->start('database.import', 'imports', 'Importing '.basename($input['relative']).' into '.$website->db_name, [
            'website_id' => $website->id,
            'path' => $input['relative'],
            'reset' => $reset,
        ]);

        return Response::json([
            'task_id' => $task->id,
            'database' => $website->db_name,
            'reset' => $reset,
            'next' => 'Call get-task with task_id '.$task->id.' every 10–20 seconds until finished is true.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'path' => $schema->string()->description('The dump inside the site folder, like dump.sql or backups/shop.sql.gz.')->required(),
            'reset' => $schema->boolean()->description('Empty the database first (drops all its tables).'),
        ];
    }
}
