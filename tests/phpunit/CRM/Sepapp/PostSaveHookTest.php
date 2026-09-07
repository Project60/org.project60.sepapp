<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the hooks that drive the SDDNG handover.
 *
 * The SDDNG processor learns the ID of the contribution the form created from
 * sepapp_civicrm_postSave_civicrm_contribution(), and completes the mandate
 * either from the Contribution.completetransaction API wrapper or from the
 * Confirm form's postProcess hook.
 *
 * Several tests here document finding C2 of code_review.md (see issue_002.md):
 * the postSave hook is unconditional and site wide, so any contribution saved
 * in the same request hijacks the handover. They are expected to fail until
 * that is fixed.
 *
 * @group headless
 */
class CRM_Sepapp_PostSaveHookTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use CRM_Sepapp_ConfigurationTrait;

  /**
   * Invoice ID linking the staged mandate data to "its" contribution.
   */
  const TEST_INVOICE_ID = 'SEPAPP-TEST-INVOICE';

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
   * Saving a contribution with no SDDNG payment in flight changes nothing.
   *
   * KNOWN DEFECT - expected to fail: the postSave hook writes the contribution
   * ID into the handover state unconditionally, which leaves a junk record
   * behind for every contribution the site ever saves. See finding C2
   * (failure mode B) in code_review.md and issue_002.md.
   */
  public function testNothingPendingLeavesNoJunkState(): void {
    $this->createTestContribution();

    $this->assertNull(CRM_Core_Payment_SDDNG::getPendingContributionID());
  }

  /**
   * An unrelated contribution must not break the next SDDNG payment.
   *
   * KNOWN DEFECT - expected to fail: the junk record left by the postSave hook
   * makes the "is a mandate already pending?" guard in doDirectPayment()
   * engage, and a perfectly valid payment is aborted with "SDD
   * PaymentProcessor NG: workflow broken." See finding C2 (failure mode B) in
   * code_review.md and issue_002.md.
   */
  public function testUnrelatedContributionDoesNotAbortValidPayment(): void {
    $unrelated = $this->createTestContribution();

    $params = $this->startNgPayment(['contributionID' => $unrelated['id'] + 1]);

    $this->assertEquals(self::TEST_IBAN, $params['iban']);
  }

  /**
   * A contribution saved in between must not steal the staged mandate.
   *
   * KNOWN DEFECT - expected to fail: the postSave hook re-points the handover
   * state to the unrelated contribution, so releasing the data for the SEPA
   * contribution hits the ID mismatch and silently returns NULL - the
   * contribution completes without a mandate and the money is never
   * collected. See finding C2 (failure mode A) in code_review.md and
   * issue_002.md.
   */
  public function testPendingMandateSurvivesUnrelatedContribution(): void {
    $this->startNgPayment(['invoiceID' => self::TEST_INVOICE_ID]);
    $sepa_contribution = $this->createTestContribution(['invoice_id' => self::TEST_INVOICE_ID]);
    $this->createTestContribution(['invoice_id' => 'SOME-OTHER-INVOICE']);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate($sepa_contribution['id']);

    $this->assertEquals($sepa_contribution['id'], $this->getSingleMandate()['entity_id']);
  }

  /**
   * The mandate must not be attached to a contribution that never asked for it.
   *
   * KNOWN DEFECT - expected to fail: on the Confirm form path the contribution
   * ID is not passed in but taken from the handover state, which the postSave
   * hook has re-pointed to the unrelated contribution. The mandate is created
   * against that one, which is then also reset to pending and stripped of its
   * financial transactions. See finding C2 (failure mode A) in code_review.md
   * and issue_002.md.
   */
  public function testUnrelatedContributionDoesNotGetTheMandate(): void {
    $this->startNgPayment(['invoiceID' => self::TEST_INVOICE_ID]);
    $sepa_contribution = $this->createTestContribution(['invoice_id' => self::TEST_INVOICE_ID]);
    $this->createTestContribution(['invoice_id' => 'SOME-OTHER-INVOICE']);

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $this->assertEquals($sepa_contribution['id'], $this->getSingleMandate()['entity_id']);
  }

  /**
   * Completing a transaction is wrapped, so the mandate can be created.
   */
  public function testApiWrapperRegisteredForCompletetransaction(): void {
    $wrappers = [];
    sepapp_civicrm_apiWrappers($wrappers, [
      'entity' => 'Contribution',
      'action' => 'completetransaction',
    ]);

    $this->assertContainsOnlyInstancesOf(CRM_Core_Payment_SDDNGPostProcessor::class, $wrappers);
  }

  /**
   * Other API calls are left alone.
   */
  public function testApiWrapperNotRegisteredForOtherCalls(): void {
    $wrappers = [];
    sepapp_civicrm_apiWrappers($wrappers, [
      'entity' => 'Contribution',
      'action' => 'create',
    ]);

    $this->assertCount(0, $wrappers);
  }

  /**
   * Completing the contribution turns the staged data into a mandate.
   */
  public function testCompletetransactionCreatesMandate(): void {
    $this->startNgPayment();
    $contribution = $this->createTestContribution([
      'contribution_status_id' => $this->getContributionStatusId('Pending'),
    ]);

    civicrm_api3('Contribution', 'completetransaction', ['id' => $contribution['id']]);

    $this->assertEquals($contribution['id'], $this->getSingleMandate()['entity_id']);
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
