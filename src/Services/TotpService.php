<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use BaconQrCode\Renderer\GenericRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();

        $digits = (int) config('auth_system.two_factor.codes.totp.digits', 6);
        $this->engine->setOneTimePasswordLength($digits);
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUri(string $email, string $secret): string
    {
        $issuer = (string) config('auth_system.two_factor.codes.totp.issuer', config('app.name', 'Laravel'));

        return $this->engine->getQRCodeUrl($issuer, $email, $secret);
    }

    public function qrSvg(string $otpauthUri, int $size = 240): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        );

        $writer = new Writer($renderer);

        return $writer->writeString($otpauthUri);
    }

    public function verify(string $secret, string $code): bool
    {
        $window = (int) config('auth_system.two_factor.codes.totp.window', 1);

        $result = $this->engine->verifyKey($secret, $code, $window);

        return $result !== false;
    }
}
