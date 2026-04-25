<?php

use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

it('generates certificates for each supported algorithm', function (string $algorithm) {
    $cert = SslHelper::generateSslCertificate(
        commonName: 'test-cert-'.$algorithm,
        serverId: $this->server->id,
        keyAlgorithm: $algorithm,
    );

    expect($cert->ssl_certificate)->toContain('-----BEGIN CERTIFICATE-----');
    expect($cert->ssl_private_key)->toContain('-----BEGIN ');

    // Sanity check: the generated key actually loads via openssl
    $loaded = openssl_pkey_get_private($cert->ssl_private_key);
    expect($loaded)->not->toBeFalse();

    $details = openssl_pkey_get_details($loaded);
    expect($details)->toBeArray();
    expect($details)->toHaveKey('type');

    if ($algorithm === StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_RSA_2048) {
        expect($details['type'])->toBe(OPENSSL_KEYTYPE_RSA);
        expect($details['bits'])->toBe(2048);
    } else {
        expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC);
    }
})->with([
    StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_PRIME256V1,
    StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_RSA_2048,
    StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_SECP521R1,
]);

it('falls back to the default algorithm when none is specified', function () {
    $cert = SslHelper::generateSslCertificate(
        commonName: 'test-default',
        serverId: $this->server->id,
    );

    $details = openssl_pkey_get_details(openssl_pkey_get_private($cert->ssl_private_key));
    // Default is secp521r1 (EC).
    expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC);
});

it('reports Hyperdrive incompatibility for secp521r1 and compatibility for the others', function () {
    $db = new StandalonePostgresql;

    expect($db->isSslAlgorithmHyperdriveCompatible(StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_PRIME256V1))->toBeTrue();
    expect($db->isSslAlgorithmHyperdriveCompatible(StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_RSA_2048))->toBeTrue();
    expect($db->isSslAlgorithmHyperdriveCompatible(StandalonePostgresql::SSL_CERTIFICATE_ALGORITHM_SECP521R1))->toBeFalse();
});
