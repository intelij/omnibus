<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\StateMachines\BookingStateMachine;
use App\StateMachines\PaymentStateMachine;
use App\Support\BookingReference;
use Tests\TestCase;

class DomainTest extends TestCase
{
    public function test_booking_reference_format(): void
    {
        $tenant = $this->makeTenant();
        $this->makeTrip($tenant);

        $ref = BookingReference::generate();
        $this->assertMatchesRegularExpression('/^TH-[2-9A-HJKMNP-Z]{6}$/', $ref);
        $this->assertStringNotContainsString('0', $ref);
        $this->assertStringNotContainsString('1', $ref);
    }

    public function test_booking_state_machine_rejects_illegal_transitions(): void
    {
        $sm = new BookingStateMachine;
        $booking = new Booking(['status' => 'confirmed']);

        $this->assertFalse($sm->canTransition($booking, 'held'));
        $this->assertTrue($sm->canTransition($booking, 'cancelled'));

        $expired = new Booking(['status' => 'expired']);
        $this->assertFalse($sm->canTransition($expired, 'confirmed'));
        $this->assertFalse($sm->canTransition($expired, 'pending_payment'));
    }

    public function test_payment_state_machine_terminals_are_terminal(): void
    {
        $sm = new PaymentStateMachine;

        foreach (['failed', 'cancelled', 'expired', 'refunded'] as $terminal) {
            $p = new Payment(['status' => $terminal]);
            $this->assertFalse($sm->canTransition($p, 'paid'));
            $this->assertFalse($sm->canTransition($p, 'pending'));
        }

        $paid = new Payment(['status' => 'paid']);
        $this->assertTrue($sm->canTransition($paid, 'refunded'));
        $this->assertTrue($sm->canTransition($paid, 'partially_refunded'));
        $this->assertFalse($sm->canTransition($paid, 'pending'));
    }

    public function test_promotion_discount_calculation(): void
    {
        $percent = new Promotion(['type' => 'percent', 'value' => 10, 'max_discount' => null]);
        $this->assertEquals(10.0, $percent->discountFor(100));

        $capped = new Promotion(['type' => 'percent', 'value' => 50, 'max_discount' => 15]);
        $this->assertEquals(15.0, $capped->discountFor(100));

        $fixed = new Promotion(['type' => 'fixed', 'value' => 7.5, 'max_discount' => null]);
        $this->assertEquals(7.5, $fixed->discountFor(100));

        // Never exceed the subtotal
        $big = new Promotion(['type' => 'fixed', 'value' => 500, 'max_discount' => null]);
        $this->assertEquals(100.0, $big->discountFor(100));
    }
}
