<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the recurring (RCUR) branch of the SDDNG post processor.
 *
 * Covers CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate() with a
 * recurring contribution, and the recurring contribution rewrite it performs
 * afterwards (updateRecurringContribution()).
 *
 * The creditor is pinned to a single cycle day, so the collection date - and
 * with it the start date of the recurring contribution - is predictable.
 *
 * @group headless
 */
class CRM_Sepapp_SddngPostProcessorRcurTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use CRM_Sepapp_ConfigurationTrait;

  /**
   * The only cycle day the test creditor collects on.
   */
  const CYCLE_DAY = 15;

  public function setUp(): void {
    parent::setUp();

    $this->resetPendingMandate();
    $this->createBasicConfiguration();
    $this->setSepaSetting('cycledays', (string) self::CYCLE_DAY, $this->creditorId);
  }

  public function tearDown(): void {
    $this->resetPendingMandate();
    $this->flushSettingsCache();
    parent::tearDown();
  }

  /**
   * The mandate describes the recurring collection, not the contribution.
   */
  public function testRcurMandateFields(): void {
    $recur = $this->createRecurringPayment();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $mandate = $this->getSingleMandate();
    $this->assertEquals('RCUR', $mandate['type'], 'mandate type');
    $this->assertEquals('FRST', $mandate['status'], 'mandate status');
    $this->assertEquals('civicrm_contribution_recur', $mandate['entity_table'], 'entity table');
    $this->assertEquals($recur['id'], $mandate['entity_id'], 'entity ID');
    $this->assertEquals($this->testContactId, $mandate['contact_id'], 'contact');
    $this->assertEquals(self::TEST_IBAN, $mandate['iban'], 'IBAN');
    $this->assertEquals(self::TEST_BIC_VALID, $mandate['bic'], 'BIC');
  }

  /**
   * The recurring contribution is handed over to the SEPA collection cycle.
   */
  public function testRecurringContributionIsSetToPendingRcur(): void {
    $recur = $this->createRecurringPayment();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $reloaded = $this->getContributionRecur($recur['id']);
    $this->assertEquals($this->getContributionStatusId('Pending'), $reloaded['contribution_status_id'], 'status');
    $this->assertEquals($this->getPaymentInstrumentId('RCUR'), $reloaded['payment_instrument_id'], 'payment instrument');
  }

  /**
   * Collection starts on the creditor's next possible collection day.
   */
  public function testStartDateIsNextPossibleCollectionDate(): void {
    $recur = $this->createRecurringPayment();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $start_date = $this->getContributionRecur($recur['id'])['start_date'];
    $this->assertEquals(self::CYCLE_DAY, (int) date('j', strtotime($start_date)), 'day of month');
    $this->assertGreaterThanOrEqual(date('Y-m-d'), date('Y-m-d', strtotime($start_date)), 'not in the past');
  }

  /**
   * The collection day is taken from the creditor's cycle days.
   */
  public function testCycleDayIsTheCollectionDay(): void {
    $recur = $this->createRecurringPayment();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEquals(self::CYCLE_DAY, $this->getContributionRecur($recur['id'])['cycle_day']);
  }

  /**
   * A cycle day chosen by the donor is overruled by the creditor's cycle days.
   *
   * SDD honors $params['cycle_day'], SDDNG discards it. The test documents the
   * current SDDNG behavior - aligning SDDNG with SDD will make it fail.
   */
  public function testCycleDayChosenByDonorIsDiscarded(): void {
    $recur = $this->createRecurringPayment(['cycle_day' => 5]);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEquals(self::CYCLE_DAY, $this->getContributionRecur($recur['id'])['cycle_day']);
  }

  /**
   * A fixed number of installments ends the collection after the last one.
   *
   * With 12 monthly installments starting on the 15th, the last collection is
   * the 15th one year later, and the end date is set 5 days before that.
   */
  public function testEndDateFollowsFromInstallments(): void {
    $recur = $this->createRecurringPayment([
      'installments' => 12,
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
    ]);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $reloaded = $this->getContributionRecur($recur['id']);
    $start_date = strtotime($reloaded['start_date']);
    $end_date = strtotime($reloaded['end_date']);
    $this->assertEquals(10, (int) date('j', $end_date), 'day of month');
    $this->assertEquals(date('n', $start_date), date('n', $end_date), 'month');
    $this->assertEquals((int) date('Y', $start_date) + 1, (int) date('Y', $end_date), 'year');
  }

  /**
   * An open ended recurring contribution stays open ended.
   */
  public function testNoEndDateWithoutInstallments(): void {
    $recur = $this->createRecurringPayment();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEmpty($this->getContributionRecur($recur['id'])['end_date']);
  }

  /**
   * The contribution created by the form is replaced by the SEPA collection.
   */
  public function testOriginalContributionIsDeleted(): void {
    $this->createRecurringPayment();
    $contribution_id = CRM_Core_Payment_SDDNG::getPendingContributionID();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $remaining = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('id', '=', $contribution_id)
      ->execute();
    $this->assertCount(0, $remaining);
  }

  /**
   * Stage a recurring SDDNG payment the way the contribution form does.
   *
   * @param array $overrides
   *   Payment parameters to override, e.g. 'installments'.
   *
   * @return array
   *   The recurring contribution.
   */
  private function createRecurringPayment(array $overrides = []): array {
    $recur = $this->createTestContributionRecur(array_intersect_key($overrides, [
      'installments' => NULL,
      'frequency_unit' => NULL,
      'frequency_interval' => NULL,
      'cycle_day' => NULL,
    ]));

    $this->startNgPayment(array_merge([
      'contributionRecurID' => $recur['id'],
      'is_recur' => TRUE,
    ], $overrides));
    $this->createTestContribution(['contribution_recur_id' => $recur['id']]);

    return $recur;
  }

  /**
   * @return array
   *   The recurring contribution with the fields the tests look at.
   */
  private function getContributionRecur(int $id): array {
    return \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id', 'payment_instrument_id', 'start_date', 'end_date', 'cycle_day')
      ->addWhere('id', '=', $id)
      ->execute()
      ->single();
  }

  /**
   * @return array
   *   The one and only SEPA mandate.
   */
  private function getSingleMandate(): array {
    $mandates = (array) \Civi\Api4\SepaMandate::get(FALSE)
      ->addSelect('*')
      ->execute()
      ->getArrayCopy();
    $this->assertCount(1, $mandates, 'exactly one mandate was created');

    return reset($mandates);
  }

}
