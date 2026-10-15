<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Deploy a project to this Laravelify server: a GitHub repository, or a folder on your computer (pushed to GitHub first), on a domain or an HTTP port.')]
class DeployProject extends Prompt
{
    public function handle(Request $request): Response
    {
        $project = trim((string) $request->get('project', ''));
        $domain = trim((string) $request->get('domain', ''));

        $source = preg_match('#^(https?://|git@|[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$)#', $project) === 1
            ? 'the repository '.$project
            : ($project !== '' ? 'the local folder '.$project.' (push it to a new private GitHub repository first with `gh repo create --private --source . --push`, and make sure the Laravelify GitHub App can read it)' : 'the project in the current folder (push it to GitHub first if it is not there yet)');

        return Response::text(
            'Deploy '.$source.' to my Laravelify server'.($domain !== '' ? ' on '.$domain : ' on a free HTTP port').".\n\n"
            ."Steps:\n"
            ."1. Look at the project's files to choose the type (list-site-types) and what it needs: database, env variables, build and start commands.\n"
            .'2. Call list-sources for the GitHub App and the Cloudflare tunnel'.($domain !== '' ? ' (use a tunnel so '.$domain.' gets HTTPS)' : '').".\n"
            ."3. Call create-website with everything set, including env values the app needs (ask me for secrets you cannot know).\n"
            ."4. Follow it with get-deployment until it finishes. If it fails, read the log, fix the cause, and deploy again.\n"
            .'5. Tell me the URL and anything I still need to do.'
        );
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('project', 'A GitHub repository (owner/repo or URL) or a local folder path.', required: false),
            new Argument('domain', 'The domain to serve it on, like app.example.com. Empty for an HTTP port.', required: false),
        ];
    }
}
