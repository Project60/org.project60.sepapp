<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the one-off (OOFF) branch of the SDDNG post processor.
 *
 * Covers CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate() without a
 * recurring contribution, and the contribution clean-up it performs
 * afterwards (resetContribution()).
 *
 * The tests follow the production order of events: doDirectPayment() stages
 * the mandate data, the contribution is then created (the postSave hook hands
 * its ID over), and finally createPendingMandate() turns the staged data into
 * a mandate.
 *
 * @group headless
 */
class CRM_Sepapp_SddngPostProcessorOoffTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use CRM_Sepapp_ConfigurationTrait;

  public function setUp(): void {
    parent::setUp();

    $this->resetPendingMandate();
    $this->createBasicConfiguration();
  }

  public function tearDown(): void {
    $this->resetPendingMandate();
    $this->flushSettingsCache();
    parent::tearDown();
  }

  /**
   * The mandate describes the one-off collection for the contribution.
   */
  public function testOoffMandateFields(): void {
    $this->startNgPayment();
    $contribution = $this->createTestContribution();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $mandate = $this->getSingleMandate();
    $this->assertEquals('OOFF', $mandate['type'], 'mandate type');
    $this->assertEquals('OOFF', $mandate['status'], 'mandate status');
    $this->assertEquals('civicrm_contribution', $mandate['entity_table'], 'entity table');
    $this->assertEquals($contribution['id'], $mandate['entity_id'], 'entity ID');
    $this->assertEquals($this->testContactId, $mandate['contact_id'], 'contact');
    $this->assertEquals(self::TEST_IBAN, $mandate['iban'], 'IBAN');
    $this->assertEquals(self::TEST_BIC_VALID, $mandate['bic'], 'BIC');
  }

  /**
   * The mandate source is limited to the 64 characters the column holds.
   */
  public function testMandateSourceIsTruncatedTo64Chars(): void {
    $source = str_repeat('a', 60) . str_repeat('b', 20);

    $this->startNgPayment();
    $this->createTestContribution(['contribution_source' => $source]);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEquals(str_repeat('a', 60) . 'bbbb', $this->getSingleMandate()['source']);
  }

  /**
   * The campaign of the contribution survives the SEPA takeover.
   *
   * createPendingMandate() reads the campaign from the contribution and passes
   * it to SepaMandate.create, but the mandate schema has no campaign column,
   * so the value is dropped there - the campaign of a one-off SEPA collection
   * lives on its contribution only. What matters is therefore that the
   * contribution keeps it while being reset to pending.
   */
  public function testCampaignIsCopiedFromContribution(): void {
    $campaign = $this->createTestCampaign();

    $this->startNgPayment();
    $contribution = $this->createTestContribution(['campaign_id' => $campaign['id']]);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $reloaded = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('campaign_id')
      ->addWhere('id', '=', $contribution['id'])
      ->execute()
      ->single();
    $this->assertEquals($campaign['id'], $reloaded['campaign_id']);
  }

  /**
   * The mandate belongs to the creditor configured on the payment processor.
   *
   * This pins down the creditor lookup flagged as C3 in code_review.md:
   * createPendingMandate() loads the creditor with api3 'get' but reads
   * $creditor['id'] as if it had used 'getsingle'. That happens to work,
   * because api3 'get' puts the ID at the top level for single results - so
   * the mandate does end up with the right creditor, as asserted here. The
   * 'currency' the same block reads is not part of the mandate schema and is
   * dropped by SepaMandate.create, so it has no effect either way.
   */
  public function testMandateBelongsToProcessorCreditor(): void {
    $other_creditor = $this->createCreditor([
      'name' => 'SEPA Lastschrift CHF',
      'label' => 'SEPA Lastschrift CHF',
      'currency' => 'CHF',
      'mandate_prefix' => 'CHF',
    ]);
    $other_processor_id = $this->createNgPaymentProcessor([
      'name' => 'SEPA Lastschrift NG CHF',
      'user_name' => (string) $other_creditor['id'],
    ]);

    $this->startNgPayment(['payment_processor_id' => $other_processor_id]);
    $this->createTestContribution();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEquals($other_creditor['id'], $this->getSingleMandate()['creditor_id']);
  }

  /**
   * The contribution is put back into the pending SEPA collection cycle.
   */
  public function testContributionIsResetToPending(): void {
    $this->startNgPayment();
    $contribution = $this->createTestContribution();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $reloaded = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contribution_status_id', 'payment_instrument_id')
      ->addWhere('id', '=', $contribution['id'])
      ->execute()
      ->single();
    $this->assertEquals($this->getContributionStatusId('Pending'), $reloaded['contribution_status_id'], 'status');
    $this->assertEquals($this->getPaymentInstrumentId('OOFF'), $reloaded['payment_instrument_id'], 'payment instrument');
  }

  /**
   * The financial transactions booked before the mandate existed are removed.
   *
   * Finding C8 of code_review.md is about the removal leaving the entity links
   * behind, which is why this test looks at both tables.
   */
  public function testFinancialTransactionsAreRemoved(): void {
    $this->startNgPayment();
    $contribution = $this->createTestContribution();
    $this->assertNotEmpty($this->getFinancialTrxnIds($contribution['id']), 'precondition: contribution is booked');

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $remaining = \Civi\Api4\FinancialTrxn::get(FALSE)
      ->addSelect('id')
      ->addWhere('id', 'IN', $this->getFinancialTrxnIds($contribution['id']))
      ->execute();
    $this->assertCount(0, $remaining);
  }

  /**
   * Without a creditor we cannot build a mandate, so we build none.
   */
  public function testNoMandateWhenProcessorHasNoCreditor(): void {
    $processor_id = $this->createNgPaymentProcessor([
      'name' => 'SEPA Lastschrift NG without creditor',
      'user_name' => '',
    ]);

    $this->startNgPayment(['payment_processor_id' => $processor_id]);
    $this->createTestContribution();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertCount(0, $this->getMandates());
  }

  /**
   * With nothing staged there is nothing to complete.
   */
  public function testCreatePendingMandateWithNothingPendingIsNoop(): void {
    $contribution = $this->createTestContribution();

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate($contribution['id']);

    $this->assertCount(0, $this->getMandates());
  }

  /**
   * @return array
   *   All SEPA mandates.
   */
  private function getMandates(): array {
    return (array) \Civi\Api4\SepaMandate::get(FALSE)
      ->addSelect('*')
      ->execute()
      ->getArrayCopy();
  }

  /**
   * @return array
   *   The one and only SEPA mandate.
   */
  private function getSingleMandate(): array {
    $mandates = $this->getMandates();
    $this->assertCount(1, $mandates, 'exactly one mandate was created');

    return reset($mandates);
  }

  /**
   * @return array
   *   The IDs of the financial transactions of a contribution.
   */
  private function getFinancialTrxnIds(int $contributionId): array {
    return (array) \Civi\Api4\EntityFinancialTrxn::get(FALSE)
      ->addSelect('financial_trxn_id')
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('entity_id', '=', $contributionId)
      ->execute()
      ->column('financial_trxn_id');
  }

}
