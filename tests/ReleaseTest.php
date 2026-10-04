<?php

use Illuminate\Filesystem\Filesystem;
use Ontob\Moonwatch\Release;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/moonwatch-release-'.uniqid();
    mkdir($this->root.'/.git/refs/heads', 0777, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

$sha = 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3';

it('reads the commit of the checked out branch', function () use ($sha) {
    file_put_contents($this->root.'/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($this->root.'/.git/refs/heads/main', $sha."\n");

    $release = new Release($this->root);

    expect($release->current())->toBe($sha)
        ->and($release->gitBranch())->toBe('main');
});

it('reads a branch from packed refs', function () use ($sha) {
    file_put_contents($this->root.'/.git/HEAD', "ref: refs/heads/release\n");
    file_put_contents($this->root.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n{$sha} refs/heads/release\n");

    expect((new Release($this->root))->current())->toBe($sha);
});

it('reads a detached head', function () use ($sha) {
    file_put_contents($this->root.'/.git/HEAD', $sha);

    $release = new Release($this->root);

    expect($release->current())->toBe($sha)
        ->and($release->gitBranch())->toBeNull();
});

it('prefers the configured release', function () use ($sha) {
    file_put_contents($this->root.'/.git/HEAD', $sha);

    expect((new Release($this->root, 'v2.4.0'))->current())->toBe('v2.4.0');
});

it('has no release without git', function () {
    expect((new Release(sys_get_temp_dir().'/moonwatch-missing-'.uniqid()))->current())->toBeNull();
});
