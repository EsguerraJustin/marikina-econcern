<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OtpTest extends TestCase
{
    public function test_generate_otp_is_numeric_and_length(): void
    {
        // generate_otp is defined in includes/otp.php
        require_once __DIR__ . '/../includes/otp.php';
        $otp = generate_otp(6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        $this->assertSame(6, strlen($otp));
        $otp8 = generate_otp(8);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $otp8);
    }

    public function test_normalize_ph_mobile_accepts_formats(): void
    {
        require_once __DIR__ . '/../includes/sms.php';
        $this->assertSame('+639171234567', normalize_ph_mobile('09171234567'));
        $this->assertSame('+639171234567', normalize_ph_mobile('+639171234567'));
        $this->assertSame('+639171234567', normalize_ph_mobile('639171234567'));
        $this->assertSame('+639171234567', normalize_ph_mobile('9171234567'));
        $this->assertFalse(normalize_ph_mobile('12345'));
        $this->assertFalse(normalize_ph_mobile('not a number'));
    }

    public function test_mask_mobile_hides_middle(): void
    {
        require_once __DIR__ . '/../includes/sms.php';
        $masked = mask_mobile('+639171234567');
        $this->assertStringStartsWith('+6391', $masked);
        $this->assertStringEndsWith('4567', $masked);
        $this->assertStringContainsString('*', $masked);
    }
}
