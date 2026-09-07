<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the SDDNG contribution/mandate handover state.
 *
 * The SDDNG processor cannot create the mandate inside doDirectPayment(),
 * because the contribution may not exist yet. It stages the mandate data in a
 * request-global static instead and completes the work later. These tests
 * cover that state machine (CRM/Core/Payment/SDDNG.php:176-241).
 *
 * @group headless
 */
class CRM_Sepapp_SddngHandoverTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

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
   * Staging mandate data must work when nothing is pending yet.
   *
   * KNOWN DEFECT - expected to fail: setPendingMandateData() calls
   * array_merge(self::$_pending_mandate, $data) on a NULL static, which is a
   * TypeError. See finding C4 in code_review.md and the note at the end of
   * issue_002.md: the fix is
   * array_merge(self::$_pending_mandate ?? [], $data).
   */
  public function testSetPendingMandateDataWithNothingPending(): void {
    CRM_Core_Payment_SDDNG::setPendingMandateData([
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'contribution_id' => 4711,
    ]);

    $this->assertEquals(4711, CRM_Core_Payment_SDDNG::getPendingContributionID());
  }

  /**
   * Releasing with nothing pending yields nothing.
   */
  public function testReleaseReturnsNullWhenNothingPending(): void {
    $this->assertNull(CRM_Core_Payment_SDDNG::releasePendingMandateData(4711));
  }

  /**
   * Mandate data without a payment processor is not usable and not released.
   */
  public function testReleaseReturnsNullWhenPaymentProcessorIdMissing(): void {
    $this->startNgPayment([
      'payment_processor_id' => NULL,
      'contributionID' => 4711,
    ]);

    $this->assertNull(CRM_Core_Payment_SDDNG::releasePendingMandateData(4711));
  }

  /**
   * A successful release consumes the pending mandate data.
   */
  public function testReleaseClearsPendingState(): void {
    $this->startNgPayment(['contributionID' => 4711]);

    $this->assertNotNull(CRM_Core_Payment_SDDNG::releasePendingMandateData(4711), 'first release');
    $this->assertNull(CRM_Core_Payment_SDDNG::releasePendingMandateData(4711), 'second release');
  }

  /**
   * The contribution ID may also arrive under the 'contributionID' key.
   */
  public function testGetPendingContributionIdFallsBackToContributionIdKey(): void {
    $this->startNgPayment(['contributionID' => 4711]);

    $this->assertEquals(4711, CRM_Core_Payment_SDDNG::getPendingContributionID());
  }

  /**
   * Handing over a contribution ID without a mandate in flight does nothing.
   */
  public function testProcessContributionWithoutPendingMandateIsNoop(): void {
    CRM_Core_Payment_SDDNG::processContribution(4711);

    $this->assertNull(CRM_Core_Payment_SDDNG::getPendingContributionID());
  }

}
