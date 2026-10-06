<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Basic tests for the SDDNG payment processor.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_Sepapp_BasicTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use CRM_Sepapp_ConfigurationTrait;

  public function setUp(): void {
    parent::setUp();

    $this->resetPendingMandate();
    $this->createBasicConfiguration();
  }

  /**
   * Clean up static and cached state after each test.
   */
  public function tearDown(): void {
    $this->resetPendingMandate();
    $this->flushSettingsCache();
    parent::tearDown();
  }


  public function testSimpleNG(): void {
    $res = $this->createTestContribution(['contribution_source' => 'TEST 3']);

    $id = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertEquals($res['id'], $id, "CRM_Core_Payment_SDDNG::getPendingContributionID");

    CRM_Core_Payment_SDDNG::setPendingMandateData([
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'contribution_id' => $res['id'],
    ]);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $sepaMandates = \Civi\Api4\SepaMandate::get(FALSE)
      ->addSelect('*')
      ->execute()->first();
    $this->assertNotEmpty($sepaMandates);
    $this->assertEquals(self::TEST_IBAN, $sepaMandates['iban']);
    $this->assertEquals('TEST 3', $sepaMandates['source']);
  }

  /**
   * A paid event registration paid via SDD must leave the participant in
   * 'Pending from pay later', not 'Registered', since the money has not been
   * collected yet - if enabled for the payment processor.
   */
  public function testEventParticipantResetNG(): void {
    Civi::settings()->set('pp_reset_participants1', 1);
    $participant_id = $this->runEventRegistration();

    $pending_status_id = \Civi\Api4\ParticipantStatusType::get(FALSE)
      ->addWhere('name', '=', 'Pending from pay later')
      ->execute()->first()['id'];
    $updated = \Civi\Api4\Participant::get(FALSE)
      ->addWhere('id', '=', $participant_id)
      ->execute()->first();
    $this->assertEquals(
      $pending_status_id,
      $updated['status_id'],
      "Participant should be reset to 'Pending from pay later'"
    );
  }

  /**
   * By default, the participant status must not be touched.
   */
  public function testEventParticipantNoResetByDefaultNG(): void {
    Civi::settings()->revert('pp_reset_participants1');
    $participant_id = $this->runEventRegistration();

    $registered_status_id = \Civi\Api4\ParticipantStatusType::get(FALSE)
      ->addWhere('name', '=', 'Registered')
      ->execute()->first()['id'];
    $updated = \Civi\Api4\Participant::get(FALSE)
      ->addWhere('id', '=', $participant_id)
      ->execute()->first();
    $this->assertEquals(
      $registered_status_id,
      $updated['status_id'],
      "Participant should stay 'Registered'"
    );
  }

  /**
   * Create a 'Registered' participant linked to an SDD contribution and
   * run the post processor on it.
   *
   * @return int participant ID
   */
  protected function runEventRegistration(): int {
    $test_id = 'event-' . mt_rand();
    $contribution = $this->createTestContribution($test_id);

    $registered_status_id = \Civi\Api4\ParticipantStatusType::get(FALSE)
      ->addWhere('name', '=', 'Registered')
      ->execute()->first()['id'];

    // create an event with a participant 'Registered' linked to the contribution
    $event = \Civi\Api4\Event::create(FALSE)->setValues([
      'title' => 'SEPA Test Event',
      'event_type_id' => 1,
      'start_date' => '2025-04-01',
      'is_active' => TRUE,
      'is_monetary' => TRUE,
    ])->execute()->first();

    $participant = \Civi\Api4\Participant::create(FALSE)->setValues([
      'contact_id' => 1,
      'event_id' => $event['id'],
      'status_id' => $registered_status_id,
    ])->execute()->first();

    \Civi\Api4\ParticipantPayment::create(FALSE)->setValues([
      'participant_id' => $participant['id'],
      'contribution_id' => $contribution['id'],
    ])->execute();

    // set test data and run the post processor (creates mandate + resets)
    CRM_Core_Payment_SDDNG::setPendingMandateData([
      'payment_processor_id' => 1,
      'iban' => self::TEST_IBAN,
      'bic' => "BEVODEBB",
    ]);
    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    return (int) $participant['id'];
  }

  public function createTestContribution(string $name): array {
    return \Civi\API4\Contribution::create(FALSE)->setValues(
      [
          'contact_id' => 1,
          'trxn_id' => 'TEST-TRX-' . $name . '-' . md5(microtime() . mt_rand()),
          'receive_date' => '01.04.2025',
          'total_amount' => '100.00',
          'currency' => 'EUR',
          'contribution_source' => 'TEST ' . $name,
          'financial_type_id' => 2,
          'payment_instrument_id' => 1,
      ]
    )->execute()->first();
  }

}
