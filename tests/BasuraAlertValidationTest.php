<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BasuraAlertValidationTest extends TestCase
{
    public function test_validate_schedules_accepts_empty(): void
    {
        $res = ba_validate_dropoff_schedules([]);
        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['cleaned']);
    }

    public function test_validate_schedules_rejects_invalid_dow(): void
    {
        $res = ba_validate_dropoff_schedules([
            ['day_of_week' => 9, 'waste_type' => 'Biodegradable', 'time_start' => '08:00:00', 'time_end' => '09:00:00']
        ]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('day-of-week', $res['error'] ?? '');
    }

    public function test_validate_schedules_rejects_missing_waste(): void
    {
        $res = ba_validate_dropoff_schedules([
            ['day_of_week' => 1, 'waste_type' => '', 'time_start' => '08:00:00', 'time_end' => '09:00:00']
        ]);
        $this->assertFalse($res['ok']);
    }

    public function test_validate_schedules_rejects_end_before_start(): void
    {
        $res = ba_validate_dropoff_schedules([
            ['day_of_week' => 1, 'waste_type' => 'Biodegradable', 'time_start' => '10:00:00', 'time_end' => '09:00:00']
        ]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('time_start', $res['error'] ?? '');
    }

    public function test_validate_schedules_accepts_valid_row(): void
    {
        $res = ba_validate_dropoff_schedules([
            ['day_of_week' => 1, 'waste_type' => 'Biodegradable', 'time_start' => '08:00:00', 'time_end' => '10:00:00', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']
        ]);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['cleaned']);
        $this->assertSame(1, $res['cleaned'][0]['day_of_week']);
    }

    public function test_validate_schedules_rejects_invalid_date_range(): void
    {
        $res = ba_validate_dropoff_schedules([
            ['day_of_week' => 1, 'waste_type' => 'Recyclable', 'time_start' => '08:00:00', 'time_end' => '09:00:00', 'effective_from' => '2026-12-31', 'effective_to' => '2026-01-01']
        ]);
        $this->assertFalse($res['ok']);
    }

    public function test_validate_operation_hours_accepts_open247(): void
    {
        $this->assertNull(ba_validate_operation_hours('any garbage 123', true));
        $this->assertNull(ba_validate_operation_hours('', true));
    }

    public function test_validate_operation_hours_accepts_valid_range(): void
    {
        $this->assertNull(ba_validate_operation_hours('Mon-Fri: 8:00 AM - 5:00 PM', false));
        $this->assertNull(ba_validate_operation_hours('09:00-17:00', false));
    }

    public function test_validate_operation_hours_rejects_single_time(): void
    {
        $err = ba_validate_operation_hours('Mon: 8:00 AM', false);
        $this->assertNotNull($err);
        $this->assertStringContainsString('Required pattern', $err ?? '');
    }

    public function test_validate_operation_hours_rejects_pure_letters(): void
    {
        $err = ba_validate_operation_hours('ewan', false);
        $this->assertNotNull($err);
    }

    public function test_validate_operation_hours_rejects_garbage_letters(): void
    {
        $err = ba_validate_operation_hours('zxdscfddvghjmk', false);
        $this->assertNotNull($err);
    }

    public function test_collection_window_rejects_equal_times(): void
    {
        // Row 403 in the local DB saved 06:58 -> 06:58 before this rule existed.
        $err = ba_validate_collection_window('06:58:00', '06:58:00');
        $this->assertNotNull($err);
        $this->assertStringContainsString('later than Start time', $err ?? '');
    }

    public function test_collection_window_rejects_end_before_start(): void
    {
        // Rows 339 (10:46 -> 01:46) and 340 (07:56 -> 04:52) in the local DB.
        $this->assertNotNull(ba_validate_collection_window('10:46:00', '01:46:00'));
        $this->assertNotNull(ba_validate_collection_window('07:56:00', '04:52:00'));
    }

    public function test_collection_window_rejects_midnight_spill(): void
    {
        // The end has no date of its own, so 00:00 after a 23:00 start is the
        // next day, not a 1-hour window.
        $this->assertNotNull(ba_validate_collection_window('23:00:00', '00:00:00'));
    }

    public function test_collection_window_accepts_a_one_minute_gap(): void
    {
        $this->assertNull(ba_validate_collection_window('07:00', '07:01'));
    }

    public function test_collection_window_compares_across_time_string_lengths(): void
    {
        // The form posts HH:MM; the DB and the row data-attributes give back
        // HH:MM:SS. Both shapes must land on the same verdict.
        $this->assertNull(ba_validate_collection_window('06:00', '08:00:00'));
        $this->assertNotNull(ba_validate_collection_window('08:00', '06:00:00'));
    }

    public function test_collection_window_accepts_a_fully_blank_window(): void
    {
        $this->assertNull(ba_validate_collection_window(null, null));
        $this->assertNull(ba_validate_collection_window('', ''));
    }

    public function test_collection_window_rejects_a_half_filled_window(): void
    {
        // Otherwise clearing the end field would be a way around the ordering rule.
        $this->assertNotNull(ba_validate_collection_window('07:00', null));
        $this->assertNotNull(ba_validate_collection_window(null, '08:00'));
    }

    public function test_collection_window_rejects_garbage_times(): void
    {
        $this->assertNotNull(ba_validate_collection_window('7am', '8am'));
        $this->assertNotNull(ba_validate_collection_window('25:00', '26:00'));
        $this->assertNotNull(ba_validate_collection_window('07:00', '08:70'));
    }
}
