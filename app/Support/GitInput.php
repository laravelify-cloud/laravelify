<?php

namespace App\Support;

use RuntimeException;

/**
 * Repository and branch values before they reach git's command line. A value starting with "-"
 * would be read as an option (like --upload-pack=..., which runs a command), so it is refused.
 */
class GitInput
{
    public static function validRepository(string $repository): bool
    {
        $repository = trim($repository);

        if ($repository === '') {
            return true;
        }

        return ! str_starts_with($repository, '-')
            && preg_match('/[\s\x00-\x1f\x7f]/', $repository) !== 1
            && preg_match('#^(https?://|ssh://|git@|file://|/|[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$)#', $repository) === 1;
    }

    /**
     * A branch name git accepts (check-ref-format), kept simple: letters, numbers, and . _ - /.
     */
    public static function validBranch(string $branch): bool
    {
        return preg_match('#^(?!-)(?!.*\.\.)(?!.*//)(?!.*@\{)[A-Za-z0-9._/-]{1,120}(?<!\.lock)(?<![/.])$#', $branch) === 1;
    }

    public static function assertSafe(string $repository, string $branch): void
    {
        if (! self::validRepository($repository)) {
            throw new RuntimeException('The repository is not a Git URL this panel can use. Fix it on the General tab.');
        }

        if (! self::validBranch($branch)) {
            throw new RuntimeException('The branch "'.$branch.'" is not a valid branch name. Fix it on the General tab.');
        }
    }
}
