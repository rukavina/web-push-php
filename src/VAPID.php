<?php

declare(strict_types=1);

/*
 * This file is part of the WebPush library.
 *
 * (c) Louis Lagrange <lagrange.louis@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Minishlink\WebPush;

use Base64Url\Base64Url;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Ecc\NistCurve;
use Jose\Component\Core\Util\Ecc\Point;
use Jose\Component\Core\Util\Ecc\PublicKey;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

class VAPID
{
    const PUBLIC_KEY_LENGTH = 65;
    const PRIVATE_KEY_LENGTH = 32;

    /**
     * @param array $vapid
     *
     * @return array
     *
     * @throws \ErrorException
     */
    public static function validate(array $vapid): array
    {
        if (!isset($vapid['subject'])) {
            throw new \ErrorException('[VAPID] You must provide a subject that is either a mailto: or a URL.');
        }

        if (isset($vapid['pemFile'])) {
            $vapid['pem'] = file_get_contents($vapid['pemFile']);

            if (!$vapid['pem']) {
                throw new \ErrorException('Error loading PEM file.');
            }
        }

        if (isset($vapid['pem'])) {
            $jwk = JWKFactory::createFromKey($vapid['pem']);
            if ($jwk->get('kty') !== 'EC' || !$jwk->has('d') || !$jwk->has('x') || !$jwk->has('y')) {
                throw new \ErrorException('Invalid PEM data.');
            }
            $publicKey = PublicKey::create(Point::create(
                gmp_init(bin2hex(Base64Url::decode($jwk->get('x'))), 16),
                gmp_init(bin2hex(Base64Url::decode($jwk->get('y'))), 16)
            ));
            $vapid['publicKey'] = base64_encode(hex2bin(Utils::serializePublicKey($publicKey)));
            $vapid['privateKey'] = base64_encode(str_pad(Base64Url::decode($jwk->get('d')), 2 * self::PRIVATE_KEY_LENGTH, '0', STR_PAD_LEFT));
        }

        if (!isset($vapid['publicKey'])) {
            throw new \ErrorException('[VAPID] You must provide a public key.');
        }

        $publicKey = Base64Url::decode($vapid['publicKey']);

        if (Utils::safeStrlen($publicKey) !== self::PUBLIC_KEY_LENGTH) {
            throw new \ErrorException('[VAPID] Public key should be 65 bytes long when decoded.');
        }

        if (!isset($vapid['privateKey'])) {
            throw new \ErrorException('[VAPID] You must provide a private key.');
        }

        $privateKey = Base64Url::decode($vapid['privateKey']);

        if (Utils::safeStrlen($privateKey) !== self::PRIVATE_KEY_LENGTH) {
            throw new \ErrorException('[VAPID] Private key should be 32 bytes long when decoded.');
        }

        return [
            'subject' => $vapid['subject'],
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ];
    }

    /**
     * This method takes the required VAPID parameters and returns the required
     * header to be added to a Web Push Protocol Request.
     *
     * @param string $audience This must be the origin of the push service
     * @param string $subject This should be a URL or a 'mailto:' email address
     * @param string $publicKey The decoded VAPID public key
     * @param string $privateKey The decoded VAPID private key
     * @param string $contentEncoding
     * @param null|int $expiration The expiration of the VAPID JWT. (UNIX timestamp)
     *
     * @return array Returns an array with the 'Authorization' and 'Crypto-Key' values to be used as headers
     * @throws \ErrorException
     */
    public static function getVapidHeaders(string $audience, string $subject, string $publicKey, string $privateKey, string $contentEncoding, int $expiration = null)
    {
        $expirationLimit = time() + 43200; // equal margin of error between 0 and 24h
        if (null === $expiration || $expiration > $expirationLimit) {
            $expiration = $expirationLimit;
        }

        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];

        $jwtPayload = json_encode([
            'aud' => $audience,
            'exp' => $expiration,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

        list($x, $y) = Utils::unserializePublicKey($publicKey);
        $jwk = JWK::create([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => Base64Url::encode($x),
            'y' => Base64Url::encode($y),
            'd' => Base64Url::encode($privateKey),
        ]);

        $jsonConverter = new StandardConverter();
        $jwsCompactSerializer = new CompactSerializer($jsonConverter);
        $jwsBuilder = new JWSBuilder($jsonConverter, AlgorithmManager::create([new ES256()]));
        $jws = $jwsBuilder
            ->create()
            ->withPayload($jwtPayload)
            ->addSignature($jwk, $header)
            ->build();

        $jwt = $jwsCompactSerializer->serialize($jws, 0);
        $encodedPublicKey = Base64Url::encode($publicKey);

        if ($contentEncoding === "aesgcm") {
            return [
                'Authorization' => 'WebPush '.$jwt,
                'Crypto-Key' => 'p256ecdsa='.$encodedPublicKey,
            ];
        } else if ($contentEncoding === 'aes128gcm') {
            return [
                'Authorization' => 'vapid t='.$jwt.', k='.$encodedPublicKey,
            ];
        }

        throw new \ErrorException('This content encoding is not supported');
    }

    /**
     * This method creates VAPID keys in case you would not be able to have a Linux bash.
     * DO NOT create keys at each initialization! Save those keys and reuse them.
     *
     * @return array
     */
    public static function createVapidKeys(): array
    {
        $curve = NistCurve::curve256();
        $privateKey = $curve->createPrivateKey();
        $publicKey = $curve->createPublicKey($privateKey);

        return [
            'publicKey' => base64_encode(hex2bin(Utils::serializePublicKey($publicKey))),
            'privateKey' => base64_encode(hex2bin(str_pad(gmp_strval($privateKey->getSecret(), 16), 2 * self::PRIVATE_KEY_LENGTH, '0', STR_PAD_LEFT)))
        ];
    }
}
