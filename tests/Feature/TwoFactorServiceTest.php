<?php

namespace Tests\Feature;

use App\Services\TwoFactorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $svc;

    /** The RFC 6238 test seed: ASCII "12345678901234567890" (20 bytes). */
    private string $rfcSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TwoFactorService;
        $this->rfcSecret = $this->svc->base32Encode('12345678901234567890');
    }

    /**
     * The 6-digit truncations of the official RFC 6238 Appendix B vectors
     * (SHA1). If this passes, the HOTP/TOTP math matches the spec every
     * authenticator app implements.
     */
    #[DataProvider('rfcVectors')]
    public function test_matches_rfc6238_vectors(int $timestamp, string $expected): void
    {
        $counter = intdiv($timestamp, 30);
        $this->assertSame($expected, $this->svc->hotp($this->rfcSecret, $counter));
        $this->assertTrue($this->svc->verify($this->rfcSecret, $expected, $timestamp));
    }

    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    public function test_verify_tolerates_one_step_of_clock_drift(): void
    {
        $now = 1111111111;
        $prev = $this->svc->hotp($this->rfcSecret, intdiv($now, 30) - 1);
        $next = $this->svc->hotp($this->rfcSecret, intdiv($now, 30) + 1);

        $this->assertTrue($this->svc->verify($this->rfcSecret, $prev, $now));
        $this->assertTrue($this->svc->verify($this->rfcSecret, $next, $now));
    }

    public function test_verify_rejects_a_code_two_steps_away(): void
    {
        $now = 1111111111;
        $twoStepsBack = $this->svc->hotp($this->rfcSecret, intdiv($now, 30) - 2);

        $this->assertFalse($this->svc->verify($this->rfcSecret, $twoStepsBack, $now));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $this->assertFalse($this->svc->verify($this->rfcSecret, '', 59));
        $this->assertFalse($this->svc->verify($this->rfcSecret, 'abcdef', 59));
        $this->assertFalse($this->svc->verify($this->rfcSecret, '12345', 59));   // too short
        $this->assertFalse($this->svc->verify($this->rfcSecret, '1234567', 59)); // too long
        $this->assertFalse($this->svc->verify($this->rfcSecret, '000000', 59));
    }

    public function test_base32_round_trips_arbitrary_bytes(): void
    {
        foreach (['a', 'foobar', random_bytes(20), '12345678901234567890'] as $raw) {
            $this->assertSame($raw, $this->svc->base32Decode($this->svc->base32Encode($raw)));
        }
    }

    public function test_generated_secret_is_valid_base32_and_usable(): void
    {
        $secret = $this->svc->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        // A code produced from the secret verifies against it (self-consistency).
        $code = $this->svc->hotp($secret, intdiv(time(), 30));
        $this->assertTrue($this->svc->verify($secret, $code));
    }

    public function test_recovery_codes_are_unique_and_well_formed(): void
    {
        $codes = $this->svc->generateRecoveryCodes(8);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }
    }
}
