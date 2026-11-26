<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * The Worker follows a review step through the FOLLOW script in its source. These run
 * that script as the sandbox would, with the step's directory as DETACH leaves it.
 */
function followScript(): string
{
    preg_match('/^const FOLLOW = `(.*?)`;$/ms', (string) file_get_contents(base_path('cloudflare/worker/src/index.ts')), $match);

    return str_replace('\\$', '$', $match[1] ?? '');
}

beforeEach(function () {
    $this->stepDirectory = sys_get_temp_dir().'/follow-'.Str::random(8);
    File::ensureDirectoryExists($this->stepDirectory);
});

afterEach(function () {
    File::deleteDirectory($this->stepDirectory);
});

it('hands a slow reader all the step printed once its exit code is there', function () {
    File::put("{$this->stepDirectory}/log", str_repeat('x', 1024 * 1024));
    File::put("{$this->stepDirectory}/code", "0\n");

    $result = Process::timeout(30)->run(['sh', '-c', 'sh -c "$0" follow "$1" 999999 2 | (sleep 2; wc -c)', followScript(), $this->stepDirectory]);

    expect((int) trim($result->output()))->toBe(1024 * 1024 - 1);
});

it('ends when the step was killed before its exit code and is left a zombie', function () {
    File::put("{$this->stepDirectory}/log", 'partial');

    // A parent that never reaps the step, as sleep does as the sandbox's PID 1.
    $parent = Process::start(['sh', '-c', 'sh -c \'echo $$ > "$0/pid"; exec sleep 30\' "$0" & exec sleep 30', $this->stepDirectory]);

    try {
        retry(50, fn () => File::size("{$this->stepDirectory}/pid") > 0 || throw new RuntimeException('No pid yet.'), 100);
        $pid = trim(File::get("{$this->stepDirectory}/pid"));
        Process::run(['kill', $pid]);

        $result = Process::timeout(10)->run(['sh', '-c', followScript(), 'follow', $this->stepDirectory, $pid, '1']);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toBe('partial');
    } finally {
        $parent->signal(SIGKILL);
    }
});
