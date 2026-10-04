<?php

namespace Ontob\Moonwatch;

/**
 * The release the events belong to: the configured value, or the commit
 * checked out in the application's .git directory. Git is read from the
 * files directly, without running a process, once per process.
 */
class Release
{
    /**
     * @var array{ref: string|null, branch: string|null}|null
     */
    protected ?array $git = null;

    public function __construct(
        protected string $basePath,
        protected ?string $configured = null,
    ) {}

    public function current(): ?string
    {
        if ($this->configured !== null && trim($this->configured) !== '') {
            return trim($this->configured);
        }

        return $this->gitRef();
    }

    public function gitRef(): ?string
    {
        return $this->git()['ref'];
    }

    public function gitBranch(): ?string
    {
        return $this->git()['branch'];
    }

    /**
     * @return array{ref: string|null, branch: string|null}
     */
    protected function git(): array
    {
        return $this->git ??= $this->readGit();
    }

    /**
     * @return array{ref: string|null, branch: string|null}
     */
    protected function readGit(): array
    {
        $none = ['ref' => null, 'branch' => null];
        $gitDir = $this->gitDirectory();

        if ($gitDir === null) {
            return $none;
        }

        $head = $this->read($gitDir.'/HEAD');

        if ($head === null) {
            return $none;
        }

        if (! str_starts_with($head, 'ref:')) {
            return ['ref' => $this->sha($head), 'branch' => null];
        }

        $ref = trim(substr($head, 4));
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;

        return ['ref' => $this->resolveRef($gitDir, $ref), 'branch' => $branch];
    }

    /**
     * The .git directory, or the one a .git file points to in a worktree.
     */
    protected function gitDirectory(): ?string
    {
        $git = rtrim($this->basePath, '/\\').'/.git';

        if (is_dir($git)) {
            return $git;
        }

        $pointer = $this->read($git);

        if ($pointer === null || ! str_starts_with($pointer, 'gitdir:')) {
            return null;
        }

        $target = trim(substr($pointer, 7));

        if (! preg_match('#^([a-zA-Z]:)?[/\\\\]#', $target)) {
            $target = rtrim($this->basePath, '/\\').'/'.$target;
        }

        return is_dir($target) ? $target : null;
    }

    protected function resolveRef(string $gitDir, string $ref): ?string
    {
        $loose = $this->read($gitDir.'/'.$ref);

        if ($loose !== null) {
            return $this->sha($loose);
        }

        $packed = $this->read($gitDir.'/packed-refs');

        if ($packed === null) {
            $common = $this->read($gitDir.'/commondir');
            $packed = $common !== null ? $this->read($gitDir.'/'.$common.'/packed-refs') : null;
        }

        foreach (preg_split('/\R/', (string) $packed) ?: [] as $line) {
            $parts = explode(' ', trim($line), 2);

            if (count($parts) === 2 && $parts[1] === $ref) {
                return $this->sha($parts[0]);
            }
        }

        return null;
    }

    protected function sha(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[0-9a-f]{40,64}$/i', $value) ? strtolower($value) : null;
    }

    protected function read(string $file): ?string
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : trim($contents);
    }
}
