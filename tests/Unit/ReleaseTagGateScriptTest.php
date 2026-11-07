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

        // Fake gh: each pulls call runs the script's real --jq filter over the
        // next line of pulls.txt (a JSON array); each check-runs call pops the
        // next line of states.txt. In both, the last line repeats.
        file_put_contents($this->dir.'/bin/gh', implode("\n", [
            '#!/usr/bin/env bash',
            'echo "$*" >> '.escapeshellarg($this->dir.'/calls.log'),
            'filter=',
            'while [ $# -gt 0 ]; do if [ "$1" = --jq ]; then filter=$2; fi; shift; done',
            'case "$(tail -n 1 '.escapeshellarg($this->dir.'/calls.log').')" in',
            '  *"/pulls"*)',
            '    pulls='.escapeshellarg($this->dir.'/pulls.txt'),
            '    head -n 1 "$pulls" | jq -r "$filter"',
            '    if [ "$(wc -l < "$pulls")" -gt 1 ]; then sed -i.bak 1d "$pulls"; fi;;',
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
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[]], ['completed success']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('not the merge result of a pull request', $output);
    }

    public function test_pull_request_link_that_appears_late_is_retried(): void
    {
        // GitHub links the pushed commit to its PR a few seconds after the
        // push, so the first lookups come back empty.
        [$code, $output] = $this->runScript('release-merged-pr.sh', [[], [], [$this->mergedPr()]], []);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('retrying (1/9)', $output);
        self::assertStringContainsString('retrying (2/9)', $output);
        self::assertStringContainsString('is the merge result of a pull request', $output);
    }

    public function test_direct_push_is_skipped_after_retries(): void
    {
        [$code, $output] = $this->runScript('release-merged-pr.sh', [[]], []);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('retrying (8/9)', $output);
        self::assertSame(9, substr_count((string) file_get_contents($this->dir.'/calls.log'), '/pulls'));
    }

    public function test_gate_waits_for_a_late_pull_request_link(): void
    {
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[], [$this->mergedPr()]], ['completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString("passed 'Test main / test'", $output);
    }

    public function test_unmerged_or_other_base_pull_requests_are_skipped(): void
    {
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[
            ['merged_at' => null, 'base' => ['ref' => 'main'], 'merge_commit_sha' => self::SHA],
            ['merged_at' => '2026-11-07T18:00:00Z', 'base' => ['ref' => 'develop'], 'merge_commit_sha' => self::SHA],
        ]], ['completed success']);

        self::assertSame(10, $code, $output);
    }

    public function test_intermediate_rebase_commit_is_skipped_without_waiting(): void
    {
        // A multi-commit rebase merge: this commit belongs to the PR, but the
        // PR's merge result (the tested main tip) is a later commit.
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[$this->mergedPr(str_repeat('f', 40))]], ['queued null']);

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
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[$this->mergedPr()]], ['completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString("passed 'Test main / test'", $output);
        self::assertStringContainsString('check_name=Test main / test', (string) file_get_contents($this->dir.'/calls.log'));
    }

    public function test_failed_tests_skip_the_commit(): void
    {
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[$this->mergedPr()]], ['completed failure']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('did not pass', $output);
    }

    public function test_running_tests_are_waited_for(): void
    {
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[$this->mergedPr()]], ['', 'in_progress null', 'completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('state: none', $output);
        self::assertStringContainsString('state: in_progress null', $output);
    }

    public function test_tests_still_running_after_timeout_fail(): void
    {
        [$code, $output] = $this->runScript('release-tag-gate.sh', [[$this->mergedPr()]], ['queued null'], '0');

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
     * @param  list<list<array<string, mixed>>>  $pulls  one response per pulls call
     * @param  list<string>  $states
     * @return array{int, string}
     */
    private function runScript(string $name, array $pulls, array $states, string $timeout = '30'): array
    {
        file_put_contents($this->dir.'/pulls.txt', implode("\n", array_map(
            static fn (array $response): string => json_encode($response, JSON_THROW_ON_ERROR),
            $pulls,
        ))."\n");
        file_put_contents($this->dir.'/states.txt', implode("\n", $states)."\n");

        $env = [
            'PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH')),
            'GITHUB_REPOSITORY=erme2/Pane',
            'RELEASE_TAG_GATE_TIMEOUT='.$timeout,
            'RELEASE_TAG_GATE_INTERVAL=0',
            'RELEASE_MERGED_PR_INTERVAL=0',
        ];
        $script = dirname(__DIR__, 2).'/bash/'.$name;
        exec(implode(' ', $env).' '.escapeshellarg($script).' '.self::SHA.' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
