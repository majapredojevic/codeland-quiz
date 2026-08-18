<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use InvalidArgumentException;

final readonly class ClientAddress
{
    /**
     * @var array<int, array{network: string, prefixLength: int}>
     */
    private array $trustedProxyNetworks;

    /**
     * @param string[] $trustedProxyCidrs
     */
    public function __construct(array $trustedProxyCidrs = [])
    {
        $trustedProxyNetworks = [];

        foreach ($trustedProxyCidrs as $trustedProxyCidr) {
            $trustedProxyNetworks[] = $this->parseCidr($trustedProxyCidr);
        }

        $this->trustedProxyNetworks = $trustedProxyNetworks;
    }

    public function identifier(
        mixed $remoteAddress,
        mixed $proxyRealAddress = null,
    ): string
    {
        $remoteAddress = $this->packAddress($remoteAddress);

        if ($remoteAddress === null) {
            return 'unknown';
        }

        if ($this->isTrustedProxy($remoteAddress)) {
            $forwardedAddress = $this->packAddress($proxyRealAddress);

            if ($forwardedAddress !== null) {
                return bin2hex($forwardedAddress);
            }
        }

        return bin2hex($remoteAddress);
    }

    /**
     * @return array{network: string, prefixLength: int}
     */
    private function parseCidr(string $cidr): array
    {
        $parts = explode('/', trim($cidr));

        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            throw new InvalidArgumentException(
                'Trusted proxy entries must be valid IPv4 or IPv6 CIDRs.',
            );
        }

        $network = $this->packAddress($parts[0]);
        $prefixLength = (int) $parts[1];
        $maximumPrefixLength = match (strlen((string) $network)) {
            4 => 32,
            16 => 128,
            default => 0,
        };

        if (
            $network === null
            || $prefixLength > $maximumPrefixLength
        ) {
            throw new InvalidArgumentException(
                'Trusted proxy entries must be valid IPv4 or IPv6 CIDRs.',
            );
        }

        return [
            'network' => $network,
            'prefixLength' => $prefixLength,
        ];
    }

    private function packAddress(mixed $address): ?string
    {
        if (!is_string($address)) {
            return null;
        }

        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packedAddress = inet_pton($address);

        return $packedAddress === false ? null : $packedAddress;
    }

    private function isTrustedProxy(string $remoteAddress): bool
    {
        foreach ($this->trustedProxyNetworks as $trustedProxyNetwork) {
            $network = $trustedProxyNetwork['network'];

            if (strlen($network) !== strlen($remoteAddress)) {
                continue;
            }

            $prefixLength = $trustedProxyNetwork['prefixLength'];
            $wholeBytes = intdiv($prefixLength, 8);
            $remainingBits = $prefixLength % 8;

            if (
                $wholeBytes > 0
                && substr($remoteAddress, 0, $wholeBytes)
                    !== substr($network, 0, $wholeBytes)
            ) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainingBits)) & 0xff;

            if (
                (ord($remoteAddress[$wholeBytes]) & $mask)
                === (ord($network[$wholeBytes]) & $mask)
            ) {
                return true;
            }
        }

        return false;
    }
}
