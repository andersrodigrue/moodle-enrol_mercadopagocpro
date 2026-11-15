<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace enrol_mercadopagocpro;

use enrol_mercadopagocpro\local\instance_settings;
use enrol_mercadopagocpro\local\status;
use enrol_mercadopagocpro\local\transaction;
use enrol_mercadopagocpro\local\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagocpro/tests/helper_trait.php');

/**
 * Regression tests: transaction identity must come from an API payment reference.
 *
 * @package enrol_mercadopagocpro
 * @covers \enrol_mercadopagocpro\local\webhook_handler
 * @covers \enrol_mercadopagocpro\local\payment_processor
 */
final class webhook_binding_test extends \advanced_testcase {
    use helper_trait;

    /**
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass}
     */
    private function prepare_purchase(): array {
        $this->setup_plugin();
        [$course, $instance] = $this->create_course_with_instance();
        $buyer = $this->getDataGenerator()->create_user();
        $txn = transaction::create($instance, $buyer, instance_settings::from_instance($instance));
        return [$course, $instance, $buyer, $txn];
    }

    /**
     * Send a signed, API-backed notification for a particular instance.
     *
     * @param string $dataid
     * @param \stdClass $instance
     * @param string $type
     * @return array
     */
    private function notify(string $dataid, \stdClass $instance, string $type = 'payment'): array {
        $requestid = 'req-binding-' . $dataid;
        $query = ['enrolid' => $instance->id];
        if ($type === 'payment') {
            $query['type'] = 'payment';
            $query['data.id'] = $dataid;
        } else {
            $query['topic'] = 'merchant_order';
            $query['id'] = $dataid;
        }
        return (new webhook_handler())->handle(
            $query,
            [
                'x-request-id' => $requestid,
                'x-signature' => $this->build_signature($dataid, $requestid, 'TEST-WEBHOOK-SECRET'),
            ],
            json_encode(['type' => $type, 'data' => ['id' => $dataid]]),
            '10.0.0.71'
        );
    }

    /**
     * The older pending transaction owns the approved payment, not the newest one.
     *
     * @return void
     */
    public function test_simultaneous_buyers_do_not_get_swapped(): void {
        [$course, $instance, $buyer, $older] = $this->prepare_purchase();
        $newerbuyer = $this->getDataGenerator()->create_user();
        $newer = transaction::create($instance, $newerbuyer, instance_settings::from_instance($instance));
        $this->mpclient->push_payment(['id' => 1122334456, 'external_reference' => $older->externalreference]);

        $this->assertSame(200, $this->notify('1122334456', $instance)['status']);
        $this->assertSame(status::ENROLMENT_ACTIVE, transaction::get((int)$older->id)->enrolmentstate);
        $this->assertSame(status::LOCAL_CREATED, transaction::get((int)$newer->id)->status);
        $context = \context_course::instance($course->id);
        $this->assertTrue(is_enrolled($context, $buyer, '', true));
        $this->assertFalse(is_enrolled($context, $newerbuyer, '', true));
    }

    /**
     * A valid payment for some other purchase cannot grant enrolment.
     *
     * @return void
     */
    public function test_unrelated_external_reference_is_ignored(): void {
        [$course, $instance, $buyer, $txn] = $this->prepare_purchase();
        $this->mpclient->push_payment(['id' => 1122334457, 'external_reference' => 'unrelated-valid-payment']);
        $this->assertSame(200, $this->notify('1122334457', $instance)['status']);
        $this->assertSame(status::LOCAL_CREATED, transaction::get((int)$txn->id)->status);
        $this->assertFalse(is_enrolled(\context_course::instance($course->id), $buyer, '', true));
    }

    /**
     * A webhook configured for course A cannot activate a payment for course B.
     *
     * @return void
     */
    public function test_cross_instance_payment_is_ignored(): void {
        [$course, $instance, $buyer, $txn] = $this->prepare_purchase();
        [$othercourse, $otherinstance] = $this->create_course_with_instance();
        $otherbuyer = $this->getDataGenerator()->create_user();
        $othertxn = transaction::create(
            $otherinstance,
            $otherbuyer,
            instance_settings::from_instance($otherinstance)
        );
        $this->mpclient->push_payment(['id' => 1122334458, 'external_reference' => $othertxn->externalreference]);
        $this->assertSame(200, $this->notify('1122334458', $instance)['status']);
        $this->assertSame(status::LOCAL_CREATED, transaction::get((int)$othertxn->id)->status);
        $this->assertFalse(is_enrolled(\context_course::instance($othercourse->id), $otherbuyer, '', true));
    }

    /**
     * Deferred webhooks retain the enrolment instance until the API lookup runs.
     *
     * @return void
     */
    public function test_deferred_notification_finds_unbound_payment(): void {
        global $DB;

        [$course, $instance, $buyer, $txn] = $this->prepare_purchase();
        set_config('deferwebhooks', 1, 'enrol_mercadopagocpro');
        $this->assertSame(200, $this->notify('1122334459', $instance)['status']);
        $log = $DB->get_record(webhook_handler::LOG_TABLE, ['dataid' => '1122334459'], '*', MUST_EXIST);
        $this->assertEquals($instance->id, $log->enrolid);
        $this->assertEmpty($log->txnid);
        $this->assertSame(status::LOCAL_CREATED, transaction::get((int)$txn->id)->status);

        $this->mpclient->push_payment(['id' => 1122334459, 'external_reference' => $txn->externalreference]);
        $result = (new webhook_handler())->retry($log);
        $this->assertTrue($result->is_handled());
        $this->assertSame(status::ENROLMENT_ACTIVE, transaction::get((int)$txn->id)->enrolmentstate);
    }

    /**
     * Merchant-order notifications resolve payments without guessing pending buyers.
     *
     * @return void
     */
    public function test_unbound_merchant_order_uses_payment_reference(): void {
        [$course, $instance, $buyer, $older] = $this->prepare_purchase();
        $newerbuyer = $this->getDataGenerator()->create_user();
        $newer = transaction::create($instance, $newerbuyer, instance_settings::from_instance($instance));

        $this->mpclient->push(['id' => 99887777, 'payments' => [['id' => 1122334460, 'status' => 'approved']]]);
        $this->mpclient->push_payment(['id' => 1122334460, 'external_reference' => $older->externalreference]);
        $this->assertSame(200, $this->notify('99887777', $instance, 'merchant_order')['status']);
        $this->assertSame(status::ENROLMENT_ACTIVE, transaction::get((int)$older->id)->enrolmentstate);
        $this->assertSame(status::LOCAL_CREATED, transaction::get((int)$newer->id)->status);
    }
}
