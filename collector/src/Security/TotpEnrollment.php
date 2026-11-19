<?php

namespace App\Security;

use App\Entity\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Activation de la double authentification : le secret proposé reste en session jusqu'à ce qu'un code
 * valide prouve que l'application d'authentification l'a bien enregistré.
 */
final class TotpEnrollment
{
    private const string PENDING = '_totp_pending';

    public function __construct(private readonly TotpAuthenticatorInterface $totp)
    {
    }

    /** @return array{secret: string, uri: string, qr: array{size: int, path: string}} */
    public function start(User $user, SessionInterface $session): array
    {
        $secret = $this->totp->generateSecret();
        $session->set(self::PENDING, $secret);
        $uri = $this->totp->getQRContent(self::pending($user, $secret));

        return [
            // Groupes de quatre caractères : plus facile à recopier à la main
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => $uri,
            'qr' => self::qr($uri),
        ];
    }

    /**
     * Code valide pour le secret en attente : active la double authentification.
     *
     * @return list<string>|null codes de secours à montrer une seule fois, null si le code est faux
     */
    public function confirm(User $user, SessionInterface $session, string $code): ?array
    {
        $secret = $session->get(self::PENDING);
        if (!\is_string($secret) || !$this->totp->checkCode(self::pending($user, $secret), preg_replace('/\D/', '', $code) ?? '')) {
            return null;
        }
        $session->remove(self::PENDING);
        $codes = self::backupCodes();
        $user->enableTotp($secret, $codes);

        return $codes;
    }

    /** @return list<string> dix codes de secours, 50 bits d'entropie chacun */
    public static function backupCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 10; ++$i) {
            $code = '';
            for ($j = 0; $j < 10; ++$j) {
                $code .= $alphabet[random_int(0, 31)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return $codes;
    }

    /**
     * QR code sous forme de tracé SVG (un carré par module) : le front le dessine lui-même, sans HTML
     * injecté ni URL data: (CSP et Trusted Types).
     *
     * @return array{size: int, path: string}
     */
    private static function qr(string $content): array
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
        $path = '';
        for ($y = 0; $y < $matrix->getHeight(); ++$y) {
            for ($x = 0; $x < $matrix->getWidth(); ++$x) {
                if (1 === $matrix->get($x, $y)) {
                    $path .= \sprintf('M%d %dh1v1h-1z', $x, $y);
                }
            }
        }

        return ['size' => $matrix->getWidth(), 'path' => $path];
    }

    /** Compte fictif portant le secret en attente, pour les fonctions TOTP du bundle */
    private static function pending(User $user, string $secret): TwoFactorInterface
    {
        return new class($user->getUsername(), $secret) implements TwoFactorInterface {
            public function __construct(private readonly string $username, private readonly string $secret)
            {
            }

            public function isTotpAuthenticationEnabled(): bool
            {
                return true;
            }

            public function getTotpAuthenticationUsername(): string
            {
                return $this->username;
            }

            public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
            {
                return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
            }
        };
    }
}
