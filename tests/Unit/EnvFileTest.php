<?php

namespace Tests\Unit;

use App\Support\EnvFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    public function test_sets_replaces_and_removes_keys_and_keeps_the_rest(): void
    {
        $content = "# App\nAPP_NAME=Shop\nexport MAIL_HOST=old\nKEEP=1\n";

        $updated = EnvFile::set($content, ['MAIL_HOST' => 'smtp.example.com', 'KEEP' => null, 'NEW_KEY' => 'x']);

        $this->assertSame("# App\nAPP_NAME=Shop\nMAIL_HOST=smtp.example.com\nNEW_KEY=x\n", $updated);
        $this->assertSame(['APP_NAME', 'MAIL_HOST', 'NEW_KEY'], EnvFile::keys($updated));
    }

    public function test_values_with_spaces_or_dollar_signs_are_quoted_so_dotenv_reads_them_literally(): void
    {
        $this->assertSame("A='two words'\nB='pa\$\$word'\nC=\"it's\"\nD=\n", EnvFile::set('', ['A' => 'two words', 'B' => 'pa$$word', 'C' => "it's", 'D' => '']));
    }

    public function test_an_app_key_stays_unquoted_and_values_are_read_back_unquoted(): void
    {
        $content = EnvFile::set('', ['APP_KEY' => 'base64:abc+/def=', 'NAME' => 'two words']);

        $this->assertStringContainsString("APP_KEY=base64:abc+/def=\n", $content);
        $this->assertSame(['APP_KEY' => 'base64:abc+/def=', 'NAME' => 'two words', 'MISSING' => null], EnvFile::values($content, ['APP_KEY', 'NAME', 'MISSING']));
    }

    public function test_rejects_bad_names_and_multiline_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFile::set('', ['1BAD' => 'x']);
    }

    public function test_rejects_values_on_several_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFile::set('', ['KEY' => "one\ntwo"]);
    }
}
