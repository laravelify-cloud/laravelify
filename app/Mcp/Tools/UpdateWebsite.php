<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use App\Support\BuildOutput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Throwable;

#[IsIdempotent]
#[Description('Change a website\'s settings: name, repository, branch, auto deploy on push, the GitHub App, PHP or Node version, install/build/start commands, and the output folder. Only the fields you pass change. Domains have their own tool (set-domains). Runtime changes (versions, commands) apply to nginx and the process file only with regenerate_files true, which replaces hand edits of those files; otherwise deploy again.')]
class UpdateWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, ConfigRenderer $renderer, HostWriter $host): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $given = collect($request->all())->except(['website', 'regenerate_files']);
        $command = ['nullable', 'string', 'max:500', 'regex:/^[^\r\n\0]*$/'];

        $rules = [
            'name' => ['string', 'max:80'],
            'repository' => ['nullable', 'string', 'max:500'],
            'branch' => ['string', 'max:120'],
            'auto_deploy' => ['boolean'],
            'github_app_id' => ['nullable', 'integer', 'exists:github_apps,id'],
            'php_version' => ['string', Rule::in(config('laravelify.php_versions'))],
            'node_version' => ['string', Rule::in(config('laravelify.node_versions'))],
            'install_command' => $command,
            'build_command' => $command,
            'start_command' => $command,
            'output_dir' => ['nullable', 'string', 'max:190', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && ! BuildOutput::safe(trim((string) $value, '/'))) {
                    $fail('Use a folder inside the repository, like dist or public (no spaces or ..).');
                }
            }],
        ];

        $validated = Validator::make($given->all(), array_intersect_key($rules, $given->all()))->validate();

        $notApplicable = [
            'php_version' => ! $website->isPhp(),
            'node_version' => ! ($website->isNode() || $website->usesNode()),
            'install_command' => ! $website->usesNode(),
            'build_command' => ! $website->usesNode(),
            'start_command' => ! $website->isNode() || $website->isNextjs(),
            'output_dir' => $website->isNode() || $website->isLaravel(),
        ];

        foreach ($notApplicable as $field => $no) {
            if ($no && array_key_exists($field, $validated)) {
                throw ValidationException::withMessages([$field => 'A '.$website->typeLabel().' website has no '.str_replace('_', ' ', $field).'.']);
            }
        }

        $blank = fn (?string $value): ?string => $value !== null && trim($value) !== '' ? trim($value) : null;
        $changes = [];

        foreach (['name', 'branch', 'php_version', 'node_version'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = trim((string) $validated[$field]);
            }
        }

        foreach (['repository', 'install_command', 'build_command', 'start_command'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $blank($validated[$field]);
            }
        }

        if (array_key_exists('auto_deploy', $validated)) {
            $changes['auto_deploy'] = (bool) $validated['auto_deploy'];
        }

        if (array_key_exists('github_app_id', $validated)) {
            $changes['github_app_id'] = $validated['github_app_id'] !== null ? (int) $validated['github_app_id'] : null;
        }

        if (array_key_exists('output_dir', $validated)) {
            $folder = trim((string) $validated['output_dir'], '/');
            $changes['public_dir'] = $folder !== '' ? $folder : ($website->isPhp() && ! $website->isLaravel() ? 'public' : null);
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['website' => 'Give at least one setting to change.']);
        }

        $website->update($changes);
        $notes = [];

        if ($request->get('regenerate_files', false)) {
            $website->nginx_config = $renderer->withServerNames($website, $renderer->nginx($website));
            $website->process_config = $renderer->process($website);
            $website->save();

            try {
                $host->writeWebsite($website);
                $notes[] = 'nginx and the process file were regenerated from the template.';
            } catch (Throwable $exception) {
                $notes[] = 'Saved, but regenerating nginx or the process file failed: '.$exception->getMessage();
            }
        } elseif (array_intersect(array_keys($changes), ['php_version', 'node_version', 'install_command', 'build_command', 'start_command', 'public_dir'])) {
            $notes[] = 'Runtime settings changed: deploy again to build with them. Pass regenerate_files true to also rewrite nginx and the process file.';
        }

        return Response::json(['changed' => array_keys($changes), 'website' => $this->websiteSummary($website->fresh()), 'notes' => $notes]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'name' => $schema->string()->description('New display name.'),
            'repository' => $schema->string()->description('Git URL, or empty to remove it.'),
            'branch' => $schema->string()->description('The branch to deploy.'),
            'auto_deploy' => $schema->boolean()->description('Deploy on every push to the branch.'),
            'github_app_id' => $schema->integer()->description('The GitHub App to clone with (list-sources); null to use the token or none.')->nullable(),
            'php_version' => $schema->string()->description('PHP sites: 8.0 to 8.5.'),
            'node_version' => $schema->string()->description('Node and built front-end sites: 20, 22, or 24.'),
            'install_command' => $schema->string()->description('One-line install command, or empty for the default.'),
            'build_command' => $schema->string()->description('One-line build command, or empty for the default.'),
            'start_command' => $schema->string()->description('Node servers: one-line start command, or empty for the default.'),
            'output_dir' => $schema->string()->description('Folder to serve: dist, out, public, …'),
            'regenerate_files' => $schema->boolean()->description('Rewrite nginx and the process file from the template (replaces hand edits).'),
        ];
    }
}
