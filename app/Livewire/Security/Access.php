<?php

namespace App\Livewire\Security;

use App\Livewire\PanelComponent;
use App\Services\AccessGate;
use App\Services\PhpMyAdminAccess;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Security → Access: a browser sign-in prompt in front of the panel and phpMyAdmin, and the
 * IPs that skip it. Saving asks for the current authenticator code.
 */
class Access extends PanelComponent
{
    /** @var array<string, array{enabled: bool, method: string, username: string, password: string}> */
    public array $targets = [];

    public string $allowlist = '';

    public int $rememberHours = 12;

    #[Locked]
    public string $myIp = '';

    public function mount(AccessGate $gate): void
    {
        foreach (AccessGate::TARGETS as $target) {
            $settings = $gate->settings($target);
            $this->targets[$target] = [
                'enabled' => $settings['enabled'],
                'method' => $settings['method'],
                'username' => $settings['username'],
                'password' => '',
            ];
        }

        $this->allowlist = implode("\n", $gate->allowlist());
        $this->rememberHours = $gate->rememberHours();
        $this->myIp = $gate->clientIp(request());
    }

    public function addMyIp(): void
    {
        $entries = AccessGate::parseAllowlist($this->allowlist);

        if (! in_array($this->myIp, $entries, true)) {
            $entries[] = $this->myIp;
        }

        $this->allowlist = implode("\n", $entries);
    }

    /**
     * Check the form. The page then asks for the authenticator code.
     */
    public function check(AccessGate $gate): bool
    {
        $this->validateForm($gate);

        return true;
    }

    public function save(AccessGate $gate, PhpMyAdminAccess $phpMyAdmin, TwoFactor $twoFactor, string $code): bool
    {
        $this->validateForm($gate);
        $this->resetErrorBag('code');

        try {
            $twoFactor->confirmCode(auth()->user(), $code, 'access-settings');
        } catch (Throwable $exception) {
            $this->addError('code', $exception->getMessage());

            return false;
        }

        $phpMyAdminWas = $gate->settings('phpmyadmin')['enabled'];

        foreach (AccessGate::TARGETS as $target) {
            $saved = $gate->settings($target);
            $form = $this->targets[$target];
            $usesPassword = $form['method'] !== AccessGate::METHOD_CODE;

            $gate->saveSettings($target, [
                'enabled' => (bool) $form['enabled'],
                'method' => $form['method'],
                'username' => $usesPassword ? trim($form['username']) : $saved['username'],
                'password_hash' => $usesPassword && $form['password'] !== '' ? Hash::make($form['password']) : $saved['password_hash'],
            ]);
            $this->targets[$target]['password'] = '';
        }

        $gate->saveAllowlist(AccessGate::parseAllowlist($this->allowlist));
        $gate->saveRememberHours($this->rememberHours);

        // The panel itself keeps letting this browser in: the right code was just entered.
        session()->put(AccessGate::SESSION_KEY, now()->addHours($gate->rememberHours())->getTimestamp());

        $phpMyAdminOn = (bool) $this->targets['phpmyadmin']['enabled'];

        if ($phpMyAdminOn !== $phpMyAdminWas || ($phpMyAdminOn && ! $this->phpMyAdminHasGate($phpMyAdmin))) {
            try {
                $phpMyAdmin->apply($phpMyAdminOn);
            } catch (Throwable $exception) {
                $gate->saveSettings('phpmyadmin', ['enabled' => $phpMyAdminWas] + $gate->settings('phpmyadmin'));
                $this->targets['phpmyadmin']['enabled'] = $phpMyAdminWas;
                $this->toast('Saved, but phpMyAdmin could not be changed: '.$exception->getMessage(), 'error');

                return true;
            }
        }

        $this->toast('Access settings saved.');

        return true;
    }

    private function phpMyAdminHasGate(PhpMyAdminAccess $phpMyAdmin): bool
    {
        $service = $phpMyAdmin->service();

        return $service !== null && $phpMyAdmin->hasGate($service->config);
    }

    private function validateForm(AccessGate $gate): void
    {
        $rules = [
            'rememberHours' => ['required', 'integer', Rule::in(AccessGate::REMEMBER_HOURS)],
            'allowlist' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (AccessGate::TARGETS as $target) {
            $rules["targets.{$target}.enabled"] = ['boolean'];
            $rules["targets.{$target}.method"] = ['required', Rule::in(AccessGate::METHODS)];

            if (($this->targets[$target]['enabled'] ?? false) && ($this->targets[$target]['method'] ?? '') !== AccessGate::METHOD_CODE) {
                $hasPassword = $gate->settings($target)['password_hash'] !== '';
                $rules["targets.{$target}.username"] = ['required', 'string', 'max:64', 'regex:/^[^:\s]+$/'];
                $rules["targets.{$target}.password"] = [$hasPassword ? 'nullable' : 'required', 'string', 'min:8', 'max:128'];
            }
        }

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                foreach (AccessGate::parseAllowlist($this->allowlist) as $entry) {
                    if (! AccessGate::validEntry($entry)) {
                        $validator->errors()->add('allowlist', '"'.$entry.'" is not an IP address or range (like 203.0.113.7 or 203.0.113.0/24).');
                    }
                }
            });
        })->validate($rules, [
            'targets.*.username.regex' => 'The username cannot have spaces or a colon.',
            'targets.*.password.required' => 'Choose a password (at least 8 characters).',
        ], [
            'targets.panel.username' => 'username',
            'targets.panel.password' => 'password',
            'targets.phpmyadmin.username' => 'username',
            'targets.phpmyadmin.password' => 'password',
        ]);
    }

    public function render(AccessGate $gate, PhpMyAdminAccess $phpMyAdmin)
    {
        return view('livewire.security.access', [
            'domains' => [
                'panel' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'the panel',
                'phpmyadmin' => $phpMyAdmin->service()?->domain ?: null,
            ],
            'hasPassword' => collect(AccessGate::TARGETS)->mapWithKeys(fn (string $target) => [$target => $gate->settings($target)['password_hash'] !== '']),
            'methods' => [
                AccessGate::METHOD_CODE => ['Authenticator code', 'Type your current 6-digit code in the username or the password box.'],
                AccessGate::METHOD_PASSWORD => ['Username and password', 'A username and password you choose here.'],
                AccessGate::METHOD_PASSWORD_CODE => ['Password + code', 'The username, then the password with your 6-digit code typed right after it.'],
            ],
            'rememberChoices' => AccessGate::REMEMBER_HOURS,
        ])->layout('layouts.app')->title('Access');
    }
}
