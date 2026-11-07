<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseTagScriptTest extends TestCase
{
    private string $dir;

    private string $work;

    private string $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/pane-release-tag-'.bin2hex(random_bytes(6));
        $this->work = $this->dir.'/work';
        $this->remote = $this->dir.'/remote.git';
        mkdir($this->work, 0700, true);

        $this->git($this->dir, 'init', '--quiet', '--bare', $this->remote);
        $this->git($this->work, 'init', '--quiet', '-b', 'main');
        $this->git($this->work, 'config', 'user.name', 'Pane Test');
        $this->git($this->work, 'config', 'user.email', 'pane@example.test');
        $this->git($this->work, 'config', 'commit.gpgsign', 'false');
        $this->git($this->work, 'config', 'tag.gpgsign', 'false');
        $this->git($this->work, 'remote', 'add', 'origin', $this->remote);
        $this->commit('initial');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    public function test_first_tag_is_first_alpha_and_is_pushed(): void
    {
        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('as v0.1.0-alpha.1', $output);
        self::assertSame(['v0.1.0-alpha.1'], $this->remoteTags());
    }

    public function test_prerelease_tag_bumps_numeric_suffix(): void
    {
        $this->tagAndPush('v0.1.0-alpha.9');
        $this->commit('next');

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertContains('v0.1.0-alpha.10', $this->remoteTags());
    }

    public function test_manual_channel_tag_is_continued(): void
    {
        $this->tagAndPush('v0.1.0-alpha.4');
        $this->commit('beta');
        $this->tagAndPush('v0.1.0-beta.1');
        $this->commit('next');

        [, $output] = $this->runScript('-p', 'no');

        self::assertSame('v0.1.0-beta.2 '.$this->head(), trim($output));
    }

    public function test_stable_tag_bumps_patch_and_sorts_after_prereleases(): void
    {
        $this->tagAndPush('v0.1.0-rc.3');
        $this->commit('stable');
        $this->tagAndPush('v0.1.0');
        $this->commit('next');

        [, $output] = $this->runScript('-p', 'no');

        self::assertSame('v0.1.1 '.$this->head(), trim($output));
    }

    public function test_already_tagged_commit_is_not_tagged_again(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('already tagged v0.1.0-alpha.1', $output);
        self::assertSame(['v0.1.0-alpha.1'], $this->remoteTags());
    }

    public function test_commit_older_than_latest_tag_is_not_tagged(): void
    {
        $older = $this->commit('older');
        $this->commit('newer');
        $this->tagAndPush('v0.1.0-alpha.1');

        [$code, $output] = $this->runScript('-c', $older);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('older than v0.1.0-alpha.1 and untagged', $output);
        self::assertSame(['v0.1.0-alpha.1'], $this->remoteTags());
    }

    public function test_earlier_untagged_merges_get_their_own_tags_in_order(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');
        $first = $this->commit('first merge');
        $second = $this->commit('second merge');
        $third = $this->commit('third merge');

        [$code, $output] = $this->runScript('-c', $third);

        self::assertSame(0, $code, $output);
        self::assertSame(
            ['v0.1.0-alpha.1', 'v0.1.0-alpha.2', 'v0.1.0-alpha.3', 'v0.1.0-alpha.4'],
            $this->remoteTags(),
        );
        self::assertSame($first, $this->remoteTagCommit('v0.1.0-alpha.2'));
        self::assertSame($second, $this->remoteTagCommit('v0.1.0-alpha.3'));
        self::assertSame($third, $this->remoteTagCommit('v0.1.0-alpha.4'));

        // The older commits' own (late) runs then find their tags.
        [$code, $output] = $this->runScript('-c', $first);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('already tagged v0.1.0-alpha.2', $output);
    }

    public function test_backfill_follows_first_parent_merges_only(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');
        $this->git($this->work, 'checkout', '--quiet', '-b', 'feature');
        $this->commit('feature work 1');
        $this->commit('feature work 2');
        $this->git($this->work, 'checkout', '--quiet', 'main');
        $this->git($this->work, 'merge', '--quiet', '--no-ff', '-m', 'merge feature', 'feature');
        $merge = $this->head();

        [, $output] = $this->runScript('-p', 'no');

        self::assertSame('v0.1.0-alpha.2 '.$merge, trim($output));
    }

    public function test_first_tag_does_not_backfill_history(): void
    {
        $this->commit('second');
        $this->commit('third');

        [, $output] = $this->runScript('-p', 'no');

        self::assertSame('v0.1.0-alpha.1 '.$this->head(), trim($output));
    }

    public function test_gate_skips_rejected_commits_and_is_not_asked_about_the_target(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');
        $broken = $this->commit('broken merge');
        $good = $this->commit('good merge');
        $target = $this->commit('target merge');
        $log = $this->dir.'/gate.log';
        $gate = $this->gate([
            'echo "$1" >> '.escapeshellarg($log),
            'if [ "$1" = '.escapeshellarg($broken).' ]; then exit 10; fi',
            'exit 0',
        ]);

        [$code, $output] = $this->runScript('-c', $target, '-g', $gate);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Skipping '.$broken.': the gate rejected it.', $output);
        self::assertSame(['v0.1.0-alpha.1', 'v0.1.0-alpha.2', 'v0.1.0-alpha.3'], $this->remoteTags());
        self::assertSame($good, $this->remoteTagCommit('v0.1.0-alpha.2'));
        self::assertSame($target, $this->remoteTagCommit('v0.1.0-alpha.3'));
        self::assertSame([$broken, $good], file($log, FILE_IGNORE_NEW_LINES));
    }

    public function test_gate_failure_stops_without_tagging(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');
        $this->commit('pending merge');
        $this->commit('target merge');
        $gate = $this->gate(['exit 1']);

        [$code, $output] = $this->runScript('-g', $gate);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('Gate failed', $output);
        self::assertSame(['v0.1.0-alpha.1'], $this->remoteTags());
    }

    public function test_concurrent_push_of_same_tag_retries_with_next_version(): void
    {
        $this->tagAndPush('v0.1.0-alpha.1');
        $this->commit('ours');

        // Another run tags a sibling commit as alpha.2 after this run has
        // fetched tags but before it pushes, so the first push is rejected.
        $other = $this->dir.'/other';
        $this->git($this->dir, 'clone', '--quiet', $this->remote, $other);
        $this->git($other, 'config', 'user.name', 'Pane Test');
        $this->git($other, 'config', 'user.email', 'pane@example.test');
        $this->git($other, 'config', 'commit.gpgsign', 'false');
        $this->git($other, 'config', 'tag.gpgsign', 'false');
        $this->git($other, 'checkout', '--quiet', 'v0.1.0-alpha.1');
        $this->git($other, 'commit', '--quiet', '--allow-empty', '-m', 'theirs');
        $this->git($other, 'tag', '-a', 'v0.1.0-alpha.2', '-m', 'other run');

        $shim = $this->dir.'/bin';
        mkdir($shim);
        $realGit = trim((string) shell_exec('command -v git'));
        file_put_contents($shim.'/git', implode("\n", [
            '#!/usr/bin/env bash',
            'if [ "$1" = push ] && [ ! -f '.escapeshellarg($this->dir.'/raced').' ]; then',
            '  touch '.escapeshellarg($this->dir.'/raced'),
            '  '.escapeshellarg($realGit).' -C '.escapeshellarg($other).' push --quiet origin refs/tags/v0.1.0-alpha.2',
            'fi',
            'exec '.escapeshellarg($realGit).' "$@"',
            '',
        ]));
        chmod($shim.'/git', 0755);

        [$code, $output] = $this->runScriptWithPath($shim);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Tag v0.1.0-alpha.2 already exists on origin; retrying', $output);
        self::assertStringContainsString('as v0.1.0-alpha.3', $output);
        self::assertSame(['v0.1.0-alpha.1', 'v0.1.0-alpha.2', 'v0.1.0-alpha.3'], $this->remoteTags());
    }

    public function test_unsupported_latest_tag_fails(): void
    {
        $this->tagAndPush('v0.1.0-alpha');
        $this->commit('next');

        [$code, $output] = $this->runScript('-p', 'no');

        self::assertSame(1, $code);
        self::assertStringContainsString("Unsupported latest tag 'v0.1.0-alpha'", $output);
    }

    /**
     * @param  list<string>  $lines
     */
    private function gate(array $lines): string
    {
        $path = $this->dir.'/gate-'.bin2hex(random_bytes(3)).'.sh';
        file_put_contents($path, implode("\n", ['#!/usr/bin/env bash', ...$lines, '']));
        chmod($path, 0755);

        return $path;
    }

    private function head(): string
    {
        return trim($this->git($this->work, 'rev-parse', 'HEAD'));
    }

    private function remoteTagCommit(string $tag): string
    {
        return trim($this->git($this->dir, '--git-dir='.$this->remote, 'rev-parse', $tag.'^{commit}'));
    }

    private function commit(string $message): string
    {
        file_put_contents($this->work.'/file.txt', $message.PHP_EOL, FILE_APPEND);
        $this->git($this->work, 'add', 'file.txt');
        $this->git($this->work, 'commit', '--quiet', '-m', $message);

        return trim($this->git($this->work, 'rev-parse', 'HEAD'));
    }

    private function tagAndPush(string $tag): void
    {
        $this->git($this->work, 'tag', '-a', $tag, '-m', $tag);
        $this->git($this->work, 'push', '--quiet', 'origin', 'refs/tags/'.$tag);
    }

    /**
     * @return list<string>
     */
    private function remoteTags(): array
    {
        $output = $this->git($this->work, 'ls-remote', '--tags', '--refs', 'origin');
        $tags = [];
        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $tags[] = substr($line, strrpos($line, 'refs/tags/') + strlen('refs/tags/'));
        }
        sort($tags);

        return $tags;
    }

    /**
     * @return array{int, string}
     */
    private function runScript(string ...$args): array
    {
        return $this->runScriptWithPath('', ...$args);
    }

    /**
     * @return array{int, string}
     */
    private function runScriptWithPath(string $pathPrefix, string ...$args): array
    {
        $path = $pathPrefix === '' ? '' : 'PATH='.escapeshellarg($pathPrefix.':'.getenv('PATH')).' ';
        $script = dirname(__DIR__, 2).'/bash/release-tag.sh';
        $command = $path.escapeshellarg($script).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
        exec('cd '.escapeshellarg($this->work).' && '.$command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private function git(string $cwd, string ...$args): string
    {
        $command = 'git '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
        exec('cd '.escapeshellarg($cwd).' && '.$command, $lines, $code);
        $output = implode("\n", $lines);
        self::assertSame(0, $code, $output);

        return $output;
    }
}
