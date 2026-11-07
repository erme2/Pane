<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseTagGateScriptTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/pane-release-gate-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin', 0700, true);

        if (trim((string) shell_exec('command -v jq')) === '') {
            self::markTestSkipped('jq is required to apply the gate\'s --jq filter.');
        }

        // Fake gh: pull requests run the gate's real --jq filter over
        // pulls.json; each check-runs call pops the next line of states.txt
        // (the last line repeats).
        file_put_contents($this->dir.'/bin/gh', implode("\n", [
            '#!/usr/bin/env bash',
            'echo "$*" >> '.escapeshellarg($this->dir.'/calls.log'),
            'filter=',
            'while [ $# -gt 0 ]; do if [ "$1" = --jq ]; then filter=$2; fi; shift; done',
            'case "$(tail -n 1 '.escapeshellarg($this->dir.'/calls.log').')" in',
            '  *"/pulls"*) jq -r "$filter" '.escapeshellarg($this->dir.'/pulls.json').';;',
            '  *"/check-runs"*)',
            '    states='.escapeshellarg($this->dir.'/states.txt'),
            '    head -n 1 "$states"',
            '    if [ "$(wc -l < "$states")" -gt 1 ]; then sed -i.bak 1d "$states"; fi;;',
            '  *) exit 1;;',
            'esac',
            '',
        ]));
        chmod($this->dir.'/bin/gh', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    public function test_commit_without_merged_pull_request_is_skipped(): void
    {
        [$code, $output] = $this->runGate([], ['completed success']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('not the merge result of a pull request', $output);
    }

    public function test_unmerged_or_other_base_pull_requests_are_skipped(): void
    {
        [$code, $output] = $this->runGate([
            ['merged_at' => null, 'base' => ['ref' => 'main'], 'merge_commit_sha' => self::SHA],
            ['merged_at' => '2026-11-07T18:00:00Z', 'base' => ['ref' => 'develop'], 'merge_commit_sha' => self::SHA],
        ], ['completed success']);

        self::assertSame(10, $code, $output);
    }

    public function test_intermediate_rebase_commit_is_skipped_without_waiting(): void
    {
        // A multi-commit rebase merge: this commit belongs to the PR, but the
        // PR's merge result (the tested main tip) is a later commit.
        [$code, $output] = $this->runGate([$this->mergedPr(str_repeat('f', 40))], ['queued null']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('not the merge result of a pull request', $output);
        self::assertStringNotContainsString('check-runs', (string) file_get_contents($this->dir.'/calls.log'));
    }

    public function test_invalid_sha_is_rejected(): void
    {
        $script = dirname(__DIR__, 2).'/bash/release-tag-gate.sh';
        exec('GITHUB_REPOSITORY=erme2/Pane '.escapeshellarg($script).' '.escapeshellarg('abc"; true').' 2>&1', $lines, $code);

        self::assertSame(2, $code);
        self::assertStringContainsString('Expected a full commit SHA', implode("\n", $lines));
    }

    public function test_passed_tests_allow_tagging(): void
    {
        [$code, $output] = $this->runGate([$this->mergedPr()], ['completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString("passed 'Test main / test'", $output);
        self::assertStringContainsString('check_name=Test main / test', (string) file_get_contents($this->dir.'/calls.log'));
    }

    public function test_failed_tests_skip_the_commit(): void
    {
        [$code, $output] = $this->runGate([$this->mergedPr()], ['completed failure']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('did not pass', $output);
    }

    public function test_running_tests_are_waited_for(): void
    {
        [$code, $output] = $this->runGate([$this->mergedPr()], ['', 'in_progress null', 'completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('state: none', $output);
        self::assertStringContainsString('state: in_progress null', $output);
    }

    public function test_tests_still_running_after_timeout_fail(): void
    {
        [$code, $output] = $this->runGate([$this->mergedPr()], ['queued null'], '0');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('Timed out', $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedPr(string $mergeCommitSha = self::SHA): array
    {
        return ['merged_at' => '2026-11-07T18:00:00Z', 'base' => ['ref' => 'main'], 'merge_commit_sha' => $mergeCommitSha];
    }

    /**
     * @param  list<array<string, mixed>>  $pulls
     * @param  list<string>  $states
     * @return array{int, string}
     */
    private function runGate(array $pulls, array $states, string $timeout = '30'): array
    {
        file_put_contents($this->dir.'/pulls.json', json_encode($pulls, JSON_THROW_ON_ERROR));
        file_put_contents($this->dir.'/states.txt', implode("\n", $states)."\n");

        $env = [
            'PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH')),
            'GITHUB_REPOSITORY=erme2/Pane',
            'RELEASE_TAG_GATE_TIMEOUT='.$timeout,
            'RELEASE_TAG_GATE_INTERVAL=0',
        ];
        $script = dirname(__DIR__, 2).'/bash/release-tag-gate.sh';
        exec(implode(' ', $env).' '.escapeshellarg($script).' '.self::SHA.' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
