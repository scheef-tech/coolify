<?php

it('accepts upstream cdn URL', function () {
    $url = 'https://cdn.coollabs.io/coolify/upgrade.sh';
    expect(assertSafeCoolifyUpdateUrl($url))->toBe($url);
});

it('accepts a github fork release URL', function () {
    $url = 'https://github.com/scheef-tech/coolify/releases/latest/download/upgrade.sh';
    expect(assertSafeCoolifyUpdateUrl($url))->toBe($url);
});

it('accepts a github tagged release URL', function () {
    $url = 'https://github.com/scheef-tech/coolify/releases/download/v4.0.0-beta.474-fork.8/upgrade.sh';
    expect(assertSafeCoolifyUpdateUrl($url))->toBe($url);
});

it('rejects http (non-https) URLs', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('http://cdn.coollabs.io/coolify/upgrade.sh'))
        ->toThrow(RuntimeException::class, 'must use HTTPS');
});

it('rejects URLs containing userinfo', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('https://user:pass@cdn.coollabs.io/coolify/upgrade.sh'))
        ->toThrow(RuntimeException::class, 'must not contain credentials');
});

it('rejects unknown hosts', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('https://evil.example.com/upgrade.sh'))
        ->toThrow(RuntimeException::class, "host 'evil.example.com' is not allowed");
});

it('rejects github.com paths that are not release-asset paths', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('https://github.com/raw-content/some/file.sh'))
        ->toThrow(RuntimeException::class, 'GitHub Release asset path');
});

it('rejects gist URLs masquerading as github.com', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('https://github.com/attacker/gist-id/raw/HEAD/upgrade.sh'))
        ->toThrow(RuntimeException::class, 'GitHub Release asset path');
});

it('rejects malformed URLs', function () {
    expect(fn () => assertSafeCoolifyUpdateUrl('not a url'))
        ->toThrow(RuntimeException::class);
});
