<?php

namespace Tests\Feature;

use App\Jobs\RunAiChatTurn;
use App\Livewire\Ai\Chat;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Models\Website;
use App\Services\AiAssistant;
use App\Services\SiteLogs;
use App\Services\TerminalSessions;
use App\Services\TwoFactor;
use App\Support\AgentStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_chat_page_lists_the_panel_tools_as_skills(): void
    {
        $this->signIn();

        $this->get(route('ai.chat'))->assertOk()
            ->assertSee('what should I do on this server?')
            ->assertSee('deploy-website')
            ->assertSee('delete-website');
    }

    public function test_sending_a_message_starts_a_chat_and_an_answer_in_the_background(): void
    {
        Queue::fake();
        $this->signIn();

        Livewire::test(Chat::class)
            ->set('message', 'Deploy the shop')
            ->call('send')
            ->assertSet('message', '');

        $chat = AiChat::query()->sole();
        $this->assertSame('Deploy the shop', $chat->title);
        $this->assertSame(['user', 'assistant'], $chat->messages->pluck('role')->all());
        $this->assertSame(AiChatMessage::RUNNING, $chat->messages->last()->status);
        Queue::assertPushed(RunAiChatTurn::class, fn (RunAiChatTurn $job) => $job->prompt === 'Deploy the shop' && $job->answerId === $chat->messages->last()->id);
    }

    public function test_a_chat_answers_one_message_at_a_time(): void
    {
        Queue::fake();
        $this->signIn();
        $chat = AiChat::query()->create(['title' => 'Busy', 'agent' => 'claude', 'model' => 'default']);
        $chat->messages()->create(['role' => 'assistant', 'text' => '', 'status' => AiChatMessage::RUNNING]);

        Livewire::test(Chat::class, ['chatId' => $chat->id])->set('message', 'Another')->call('send');

        Queue::assertNothingPushed();
    }

    public function test_switching_agent_hands_over_what_was_said(): void
    {
        Queue::fake();
        $this->signIn();
        $chat = AiChat::query()->create(['title' => 'Shop', 'agent' => 'claude', 'model' => 'default', 'session_id' => 'abc']);
        $chat->messages()->create(['role' => 'user', 'text' => 'Deploy the shop', 'status' => AiChatMessage::DONE]);
        $chat->messages()->create(['role' => 'assistant', 'text' => 'Deployed.', 'status' => AiChatMessage::DONE]);

        Livewire::test(Chat::class, ['chatId' => $chat->id])->set('agent', 'cursor')->set('message', 'Now its logs')->call('send');

        Queue::assertPushed(RunAiChatTurn::class, fn (RunAiChatTurn $job) => str_contains($job->prompt, 'User: Deploy the shop') && str_ends_with($job->prompt, 'Now its logs'));
        $this->assertNull($chat->fresh()->session_id);
        $this->assertSame('cursor', $chat->fresh()->agent);
    }

    public function test_an_answer_streams_in_with_the_tools_the_agent_used(): void
    {
        $chat = AiChat::query()->create(['title' => 'Shop', 'agent' => 'claude', 'model' => 'default']);
        $answer = $chat->messages()->create(['role' => 'assistant', 'text' => '', 'status' => AiChatMessage::RUNNING]);
        $lines = [
            ['type' => 'system', 'session_id' => 'sess-1'],
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'mcp__laravelify__deploy-website', 'input' => ['website' => 'shop']]]]],
            ['type' => 'stream_event', 'event' => ['delta' => ['type' => 'text_delta', 'text' => 'Deploying ']]],
            ['type' => 'stream_event', 'event' => ['delta' => ['type' => 'text_delta', 'text' => 'the shop.']]],
            ['type' => 'result', 'result' => 'Deploying the shop.', 'is_error' => false],
        ];
        $script = 'foreach ('.var_export(array_map('json_encode', $lines), true).' as $line) { echo $line, PHP_EOL; }';
        $assistant = Mockery::mock(AiAssistant::class);
        $assistant->shouldReceive('command')->andReturn([PHP_BINARY, '-r', $script]);
        $this->app->instance(AiAssistant::class, $assistant);

        RunAiChatTurn::dispatchSync($answer->id, 'Deploy the shop');

        $answer->refresh();
        $this->assertSame(AiChatMessage::DONE, $answer->status);
        $this->assertSame('Deploying the shop.', $answer->text);
        $this->assertSame('deploy-website', $answer->tools[0]['name']);
        $this->assertSame('sess-1', $chat->fresh()->session_id);
    }

    public function test_text_after_a_tool_call_starts_a_new_paragraph_once(): void
    {
        $chat = AiChat::query()->create(['title' => 'Shop', 'agent' => 'cursor', 'model' => 'auto']);
        $answer = $chat->messages()->create(['role' => 'assistant', 'text' => '', 'status' => AiChatMessage::RUNNING]);
        $text = fn (string $value, bool $piece = true) => ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $value]]]] + ($piece ? ['timestamp_ms' => 1] : []);
        $lines = [
            $text('Reading the logs.'),
            ['type' => 'tool_call', 'subtype' => 'started', 'tool_call' => ['mcpToolCall' => ['args' => ['toolName' => 'get-logs']]]],
            $text('Redis is down.'),
            $text('Reading the logs.Redis is down.'),
        ];
        $script = 'foreach ('.var_export(array_map('json_encode', $lines), true).' as $line) { echo $line, PHP_EOL; }';
        $assistant = Mockery::mock(AiAssistant::class);
        $assistant->shouldReceive('command')->andReturn([PHP_BINARY, '-r', $script]);
        $this->app->instance(AiAssistant::class, $assistant);

        RunAiChatTurn::dispatchSync($answer->id, 'Why is it down?');

        $this->assertSame("Reading the logs.\n\nRedis is down.", $answer->refresh()->text);
    }

    public function test_an_agent_out_of_usage_says_to_switch(): void
    {
        $chat = AiChat::query()->create(['title' => 'Shop', 'agent' => 'claude', 'model' => 'default']);
        $answer = $chat->messages()->create(['role' => 'assistant', 'text' => '', 'status' => AiChatMessage::RUNNING]);
        $assistant = Mockery::mock(AiAssistant::class);
        $assistant->shouldReceive('command')->andReturn([PHP_BINARY, '-r', 'echo json_encode(["type" => "result", "result" => "Claude usage limit reached", "is_error" => true]), PHP_EOL; exit(1);']);
        $this->app->instance(AiAssistant::class, $assistant);

        RunAiChatTurn::dispatchSync($answer->id, 'Hi');

        $answer->refresh();
        $this->assertSame(AiChatMessage::ERROR, $answer->status);
        $this->assertStringContainsString('pick another agent', $answer->text);
    }

    public function test_a_pasted_image_goes_with_the_message_and_shows_in_the_chat(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->signIn();

        Livewire::test(Chat::class)
            ->set('pasted', UploadedFile::fake()->image('screen.png', 40, 30))
            ->assertSet('attached.0.kind', 'image')
            ->assertSet('attached.0.label', 'Image #1')
            ->set('message', 'What is wrong here?')
            ->call('send')
            ->assertSet('attached', []);

        $message = AiChatMessage::query()->where('role', 'user')->sole();
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        Queue::assertPushed(RunAiChatTurn::class, fn (RunAiChatTurn $job) => $job->attachments[0]['kind'] === 'image');
        $this->get(route('ai.attachment', [$message, 0]))->assertOk();

        auth()->logout();
        $this->get(route('ai.attachment', [$message, 0]))->assertRedirect(route('login'));
    }

    public function test_a_pasted_image_shows_as_a_thumbnail_before_it_is_sent(): void
    {
        Storage::fake('local');
        $this->signIn();

        $component = Livewire::test(Chat::class)->set('pasted', UploadedFile::fake()->image('screen.png', 40, 30));
        $file = basename($component->get('attached.0.path'));

        $component->assertSee(route('ai.pending', $file));
        $this->get(route('ai.pending', $file))->assertOk();
        $this->get('/ai/pending/..%2F..%2F.env')->assertNotFound();
    }

    public function test_claudes_effort_is_kept_on_the_chat_and_passed_to_it(): void
    {
        Queue::fake();
        $this->signIn();

        Livewire::test(Chat::class)->set('effort', 'high')->set('message', 'Think hard')->call('send');
        $this->assertSame('high', AiChat::query()->sole()->effort);

        Livewire::test(Chat::class)->set('agent', 'cursor')->set('effort', 'high')->set('message', 'Hi')->call('send');
        $this->assertNull(AiChat::query()->latest('id')->first()->effort);
    }

    public function test_only_images_can_be_pasted(): void
    {
        $this->signIn();

        Livewire::test(Chat::class)
            ->set('pasted', UploadedFile::fake()->create('notes.txt', 2, 'text/plain'))
            ->assertHasErrors('pasted')
            ->assertSet('attached', []);
    }

    public function test_a_websites_error_log_is_attached_with_its_last_lines(): void
    {
        $this->signIn();
        $website = Website::factory()->create(['name' => 'Shop']);
        $logs = Mockery::mock(SiteLogs::class);
        $logs->shouldReceive('sources')->andReturn([['key' => 'laravel', 'label' => 'Laravel', 'group' => 'Application', 'path' => '/x', 'size' => '1 KB', 'modified' => null]]);
        $logs->shouldReceive('tail')->with(Mockery::on(fn ($site) => $site->is($website)), 'laravel', 150)->andReturn('[2026-10-24] production.ERROR: boom');
        $this->app->instance(SiteLogs::class, $logs);

        Livewire::test(Chat::class)
            ->call('openPicker', 'log')
            ->set('logWebsite', $website->id)
            ->assertSee('Laravel')
            ->call('addLog', 'laravel')
            ->assertSet('attached.0.label', 'Shop · Laravel')
            ->assertSet('attached.0.text', '[2026-10-24] production.ERROR: boom')
            ->assertSet('picker', null);
    }

    public function test_a_repository_is_attached_once(): void
    {
        $this->signIn();

        Livewire::test(Chat::class)
            ->call('addRepository', 3, 'acme/shop', 'main')
            ->call('addRepository', 3, 'acme/shop', 'main')
            ->assertCount('attached', 1)
            ->assertSet('attached.0', ['kind' => 'repo', 'app' => 3, 'name' => 'acme/shop', 'branch' => 'main', 'label' => 'acme/shop']);
    }

    public function test_attachments_are_described_at_the_top_of_the_prompt(): void
    {
        $chat = AiChat::query()->create(['title' => 'Shop', 'agent' => 'claude', 'model' => 'default']);

        $prompt = app(AiAssistant::class)->withAttachments($chat, [['kind' => 'log', 'label' => 'Shop · Laravel', 'text' => 'production.ERROR: boom']], 'Why does it fail?');

        $this->assertStringStartsWith('Attached to this message:', $prompt);
        $this->assertStringContainsString("The last lines of the log \"Shop · Laravel\":\n```\nproduction.ERROR: boom\n```", $prompt);
        $this->assertStringEndsWith('Why does it fail?', $prompt);
    }

    public function test_signing_an_agent_in_shows_its_link_takes_its_code_and_finishes(): void
    {
        $this->signIn();
        $assistant = Mockery::mock(AiAssistant::class);
        $assistant->shouldReceive('loginCommand')->with('claude')->andReturn(['claude', 'auth', 'login']);
        $assistant->shouldReceive('prepare');
        $assistant->shouldReceive('status')->andReturn(['installed' => true, 'signedIn' => true, 'who' => 'me@example.com']);
        $assistant->shouldReceive('skills')->andReturn([]);
        $this->app->instance(AiAssistant::class, $assistant);
        $sessions = Mockery::mock(TerminalSessions::class);
        $sessions->shouldReceive('startArguments')->once()->andReturn('session-1');
        $sessions->shouldReceive('read')->andReturn(
            ['data' => base64_encode("\e[1mBrowser didn't open? Use the url below:\e[0m\r\nhttps://claude.ai/oauth/authorize?code=true&state=abc\r\nPaste code here if prompted > "), 'offset' => 120, 'done' => false, 'exit' => null],
            ['data' => base64_encode("\r\nLogin successful.\r\n"), 'offset' => 140, 'done' => true, 'exit' => 0],
        );
        $sessions->shouldReceive('write')->once()->with('session-1', "abc#def\r", Mockery::any())->andReturn(true);
        $this->app->instance(TerminalSessions::class, $sessions);

        $component = Livewire::test(Chat::class)
            ->call('startLogin', 'claude')
            ->call('pollLogin')
            ->assertSee('https://claude.ai/oauth/authorize?code=true&amp;state=abc', false)
            ->assertSee('Paste the code the page shows')
            ->set('loginAnswer', ' abc#def ')
            ->call('answerLogin')
            ->assertSee('Checking the code')
            ->call('pollLogin')
            ->assertSet('loginExit', 0)
            ->assertSee('Signed in as me@example.com');

        $component->call('closeLogin')->assertSet('login', null);
    }

    public function test_a_device_code_is_read_from_the_sign_in(): void
    {
        $state = AgentStream::login("To authenticate, visit https://github.com/login/device and enter code ABCD-1234.\n");

        $this->assertSame('https://github.com/login/device', $state['url']);
        $this->assertSame('ABCD-1234', $state['code']);
        $this->assertFalse($state['needsInput']);
    }

    public function test_cursor_and_copilot_lines_are_read_too(): void
    {
        $this->assertSame([['text', 'Hi', true]], AgentStream::parse('cursor', json_encode(['type' => 'assistant', 'timestamp_ms' => 1, 'message' => ['content' => [['type' => 'text', 'text' => 'Hi']]]])));
        $this->assertSame([['tool', 'Read', ['path' => 'a.php']]], AgentStream::parse('cursor', json_encode(['type' => 'tool_call', 'subtype' => 'started', 'tool_call' => ['readToolCall' => ['args' => ['path' => 'a.php']]]])));
        $this->assertSame([['tool', 'laravelify-get-logs', ['website' => 'shop']]], AgentStream::parse('cursor', json_encode(['type' => 'tool_call', 'subtype' => 'started', 'tool_call' => ['mcpToolCall' => ['args' => ['toolName' => 'laravelify-get-logs', 'args' => ['website' => 'shop']]]]])));
        $this->assertSame([['text', 'Done', true]], AgentStream::parse('copilot', json_encode(['type' => 'assistant.message_delta', 'delta' => 'Done'])));
        $this->assertSame([], AgentStream::parse('claude', 'not json'));
        $this->assertSame('deploy-website', AgentStream::toolName('laravelify-deploy-website'));
    }
}
