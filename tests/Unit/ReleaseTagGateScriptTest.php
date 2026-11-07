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

        // Fake gh: pull requests come from merged.txt; each check-runs call
        // pops the next line of states.txt (the last line repeats).
        file_put_contents($this->dir.'/bin/gh', implode("\n", [
            '#!/usr/bin/env bash',
            'echo "$*" >> '.escapeshellarg($this->dir.'/calls.log'),
            'case "$*" in',
            '  *"/pulls"*) cat '.escapeshellarg($this->dir.'/merged.txt').';;',
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
        [$code, $output] = $this->runGate('0', ['completed success']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('no merged pull request', $output);
    }

    public function test_passed_tests_allow_tagging(): void
    {
        [$code, $output] = $this->runGate('1', ['completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString("passed 'Test main / test'", $output);
        self::assertStringContainsString('check_name=Test main / test', (string) file_get_contents($this->dir.'/calls.log'));
    }

    public function test_failed_tests_skip_the_commit(): void
    {
        [$code, $output] = $this->runGate('1', ['completed failure']);

        self::assertSame(10, $code, $output);
        self::assertStringContainsString('did not pass', $output);
    }

    public function test_running_tests_are_waited_for(): void
    {
        [$code, $output] = $this->runGate('1', ['', 'in_progress null', 'completed success']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('state: none', $output);
        self::assertStringContainsString('state: in_progress null', $output);
    }

    public function test_tests_still_running_after_timeout_fail(): void
    {
        [$code, $output] = $this->runGate('1', ['queued null'], '0');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('Timed out', $output);
    }

    /**
     * @param  list<string>  $states
     * @return array{int, string}
     */
    private function runGate(string $merged, array $states, string $timeout = '30'): array
    {
        file_put_contents($this->dir.'/merged.txt', $merged."\n");
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
