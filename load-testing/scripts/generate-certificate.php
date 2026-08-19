<?php

declare(strict_types=1);

$outputDirectory = $argv[1] ?? '';

if ($outputDirectory === '') {
    fwrite(STDERR, "Usage: php generate-certificate.php <output-directory>\n");
    exit(1);
}

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0700, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('TLS output directory could not be created.');
}

$configurationPath = tempnam(sys_get_temp_dir(), 'clq-load-openssl-');

if (!is_string($configurationPath)) {
    throw new RuntimeException('Temporary OpenSSL configuration could not be created.');
}

$configuration = <<<'CONFIG'
[ req ]
distinguished_name = distinguished_name
prompt = no
req_extensions = v3_req

[ distinguished_name ]
CN = quiz.load.test
O = CodeLand Quiz Load Testing

[ v3_req ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = quiz.load.test
DNS.2 = localhost
IP.1 = 127.0.0.1
CONFIG;

try {
    if (file_put_contents($configurationPath, $configuration, LOCK_EX) !== strlen($configuration)) {
        throw new RuntimeException('Temporary OpenSSL configuration could not be written.');
    }

    $options = [
        'config' => $configurationPath,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'req_extensions' => 'v3_req',
        'x509_extensions' => 'v3_req',
    ];
    $privateKey = openssl_pkey_new($options);

    if ($privateKey === false) {
        throw new RuntimeException('Ephemeral TLS private key could not be generated.');
    }

    $csr = openssl_csr_new(
        ['commonName' => 'quiz.load.test', 'organizationName' => 'CodeLand Quiz Load Testing'],
        $privateKey,
        $options,
    );

    if ($csr === false) {
        throw new RuntimeException('Ephemeral TLS certificate request could not be generated.');
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 7, $options);

    if ($certificate === false) {
        throw new RuntimeException('Ephemeral TLS certificate could not be signed.');
    }

    $certificatePem = '';
    $privateKeyPem = '';

    if (!openssl_x509_export($certificate, $certificatePem)) {
        throw new RuntimeException('Ephemeral TLS certificate could not be exported.');
    }
    if (!openssl_pkey_export($privateKey, $privateKeyPem, null, $options)) {
        throw new RuntimeException('Ephemeral TLS private key could not be exported.');
    }

    $certificatePath = rtrim($outputDirectory, '/') . '/fullchain.pem';
    $privateKeyPath = rtrim($outputDirectory, '/') . '/privkey.pem';

    if (file_put_contents($certificatePath, $certificatePem, LOCK_EX) !== strlen($certificatePem)) {
        throw new RuntimeException('Ephemeral TLS certificate could not be written.');
    }
    if (file_put_contents($privateKeyPath, $privateKeyPem, LOCK_EX) !== strlen($privateKeyPem)) {
        throw new RuntimeException('Ephemeral TLS private key could not be written.');
    }
    chmod($certificatePath, 0644);
    chmod($privateKeyPath, 0600);
    echo "Generated ephemeral certificate for quiz.load.test.\n";
} finally {
    @unlink($configurationPath);
}
