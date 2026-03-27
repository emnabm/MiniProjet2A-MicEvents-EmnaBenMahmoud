<?php
namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class WebAuthnService
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct(
        private RequestStack $requestStack,
        string $appDomain = 'localhost',
        string $rpName = 'Event Reservation App'
    ) {
        $this->rpId = $appDomain;
        $this->rpName = $rpName;
        $this->origin = 'http://' . $appDomain . ':8000';
        if ($appDomain !== 'localhost') {
            $this->origin = 'https://' . $appDomain;
        }
    }


    public function generateRegistrationOptions(string $userId, string $userEmail): array
    {
        $challenge = $this->generateChallenge();
        $session = $this->requestStack->getSession();
        $session->set('webauthn_reg_challenge', $challenge);
        $session->set('webauthn_reg_user_id', $userId);

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rp' => [
                'name' => $this->rpName,
                'id' => $this->rpId,
            ],
            'user' => [
                'id' => $this->base64UrlEncode($userId),
                'name' => $userEmail,
                'displayName' => $userEmail,
            ],
            'pubKeyCredParams' => [
                ['alg' => -7, 'type' => 'public-key'], 
                ['alg' => -257, 'type' => 'public-key'], 
            ],
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey' => 'preferred',
            ],
            'timeout' => 60000,
            'attestation' => 'none',
        ];
    }

    public function verifyRegistration(array $credential): array
    {
        $session = $this->requestStack->getSession();
        $expectedChallenge = $session->get('webauthn_reg_challenge');

        if (!$expectedChallenge) {
            throw new \Exception('Session expirée. Réessayez.');
        }

        $clientDataJSON = $this->base64UrlDecode($credential['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        if ($clientData['type'] !== 'webauthn.create') {
            throw new \Exception('Type de credential invalide.');
        }

        $receivedChallenge = $this->base64UrlDecode($clientData['challenge']);
        if ($receivedChallenge !== $expectedChallenge) {
            throw new \Exception('Challenge invalide.');
        }

        if ($clientData['origin'] !== $this->origin) {
            throw new \Exception('Origin invalide: ' . $clientData['origin'] . ' !== ' . $this->origin);
        }

        $attestationObject = $this->base64UrlDecode($credential['response']['attestationObject']);
        $authData = $this->parseAttestationObject($attestationObject);

        $publicKey = $this->extractPublicKey($authData);
        $credentialId = $credential['id'];

        $session->remove('webauthn_reg_challenge');

        return [
            'credential_id' => $credentialId,
            'public_key' => $publicKey,
            'sign_count' => $authData['signCount'],
        ];
    }

    public function generateAuthenticationOptions(): array
    {
        $challenge = $this->generateChallenge();
        $session = $this->requestStack->getSession();
        $session->set('webauthn_auth_challenge', $challenge);

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rpId' => $this->rpId,
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];
    }

    public function verifyAuthentication(array $credential, string $publicKeyPem, int $storedSignCount): bool
    {
        $session = $this->requestStack->getSession();
        $expectedChallenge = $session->get('webauthn_auth_challenge');

        if (!$expectedChallenge) {
            throw new \Exception('Session expired.');
        }

        $clientDataJSON = $this->base64UrlDecode($credential['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        if ($clientData['type'] !== 'webauthn.get') {
            throw new \Exception('Invalid type.');
        }

        $receivedChallenge = $this->base64UrlDecode($clientData['challenge']);
        if ($receivedChallenge !== $expectedChallenge) {
            throw new \Exception('Invalid challenge.');
        }

        if ($clientData['origin'] !== $this->origin) {
            throw new \Exception('  Invalid origin.');
        }

        $authData = $this->base64UrlDecode($credential['response']['authenticatorData']);
        $rpIdHash = hash('sha256', $this->rpId, true);

        if (substr($authData, 0, 32) !== $rpIdHash) {
            throw new \Exception('Invalid RP ID hash.');
        }

        $flags = ord($authData[32]);
        if (!($flags & 0x01)) {
            throw new \Exception('User not present.');
        }

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signatureBase = $authData . $clientDataHash;
        $signature = $this->base64UrlDecode($credential['response']['signature']);

        $verified = openssl_verify($signatureBase, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new \Exception('Invalid Signature.');
        }

        $session->remove('webauthn_auth_challenge');
        return true;
    }

    private function generateChallenge(): string
    {
        return random_bytes(32);
    }

    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        return base64_decode($padded);
    }

    private function parseAttestationObject(string $attestationObject): array
    {
 
        $authDataPos = strpos($attestationObject, 'authData');
        if ($authDataPos === false) {
          
            $key = "\x68authData";
            $pos = strpos($attestationObject, $key);
            if ($pos === false) {
                throw new \Exception('authData not found in attestationObject');
            }
            $authDataStart = $pos + strlen($key);
        } else {
            $authDataStart = $authDataPos + strlen('authData');
        }

        $authData = $this->cborDecodeAuthData($attestationObject);

        return $authData;
    }

    private function cborDecodeAuthData(string $data): array
    {
        $offset = 0;
        $map = $this->cborDecode($data, $offset);

        $authDataBytes = $map['authData'] ?? null;
        if (!$authDataBytes) {
            throw new \Exception('missing authData ');
        }

        return $this->parseAuthData($authDataBytes);
    }

    private function parseAuthData(string $authData): array
    {
        $offset = 0;

        $rpIdHash = substr($authData, $offset, 32);
        $offset += 32;

        $flags = ord($authData[$offset]);
        $offset += 1;

        $signCount = unpack('N', substr($authData, $offset, 4))[1];
        $offset += 4;

        $attestedCredentialData = null;
        if ($flags & 0x40) {
            $aaguid = substr($authData, $offset, 16);
            $offset += 16;

            $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;

            $credentialId = substr($authData, $offset, $credIdLen);
            $offset += $credIdLen;

            $publicKeyData = substr($authData, $offset);
            $pkOffset = 0;
            $publicKey = $this->cborDecode($publicKeyData, $pkOffset);

            $attestedCredentialData = [
                'aaguid' => $aaguid,
                'credentialId' => $credentialId,
                'publicKey' => $publicKey,
            ];
        }

        return [
            'rpIdHash' => $rpIdHash,
            'flags' => $flags,
            'signCount' => $signCount,
            'attestedCredentialData' => $attestedCredentialData,
        ];
    }

    private function extractPublicKey(array $authData): string
    {
        $publicKeyData = $authData['attestedCredentialData']['publicKey'] ?? null;
        if (!$publicKeyData) {
            throw new \Exception('Clé publique non trouvée');
        }

        $kty = $publicKeyData[1] ?? null;
        $x = $publicKeyData[-2] ?? null;
        $y = $publicKeyData[-3] ?? null;

        if ($kty == 2 && $x && $y) {
            return $this->buildEcPublicKeyPem($x, $y);
        }

        $n = $publicKeyData[-1] ?? null;
        $e = $publicKeyData[-2] ?? null;
        if ($kty == 3 && $n && $e) {
            return $this->buildRsaPublicKeyPem($n, $e);
        }

        throw new \Exception('Type de clé non supporté: kty=' . $kty);
    }

    private function buildEcPublicKeyPem(string $x, string $y): string
    {
        $oid = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $point = "\x04" . $x . $y; 
        $bitString = "\x03" . chr(strlen($point) + 1) . "\x00" . $point;
        $subjectPublicKeyInfo = "\x30" . chr(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return "BEGIN PUBLIC KEY\n" .
               chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n") .
               "END PUBLIC KEY\n";
    }

    private function buildRsaPublicKeyPem(string $n, string $e): string
    {
        $key = openssl_pkey_get_public([
            'n' => $n,
            'e' => $e,
        ]);
        $details = openssl_pkey_get_details($key);
        return $details['key'];
    }

    private function cborDecode(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new \Exception('CBOR: fin de données inattendue');
        }

        $byte = ord($data[$offset++]);
        $majorType = ($byte >> 5) & 0x07;
        $additionalInfo = $byte & 0x1f;

        $value = match(true) {
            $additionalInfo < 24 => $additionalInfo,
            $additionalInfo === 24 => ord($data[$offset++]),
            $additionalInfo === 25 => unpack('n', substr($data, ($offset += 2) - 2, 2))[1],
            $additionalInfo === 26 => unpack('N', substr($data, ($offset += 4) - 4, 4))[1],
            default => throw new \Exception('CBOR:unsupported  additionalInfo : ' . $additionalInfo),
        };

        return match($majorType) {
            0 => $value, 
            1 => -1 - $value, 
            2 => $this->readBytes($data, $offset, $value), 
            3 => $this->readBytes($data, $offset, $value), 
            4 => $this->readArray($data, $offset, $value), 
            5 => $this->readMap($data, $offset, $value), 
            default => throw new \Exception('CBOR: type majeur non supporté: ' . $majorType),
        };
    }

    private function readBytes(string $data, int &$offset, int $length): string
    {
        $bytes = substr($data, $offset, $length);
        $offset += $length;
        return $bytes;
    }

    private function readArray(string $data, int &$offset, int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->cborDecode($data, $offset);
        }
        return $result;
    }

    private function readMap(string $data, int &$offset, int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->cborDecode($data, $offset);
            $val = $this->cborDecode($data, $offset);
            $result[$key] = $val;
        }
        return $result;
    }
}