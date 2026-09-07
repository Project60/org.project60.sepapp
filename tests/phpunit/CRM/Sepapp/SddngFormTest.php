<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the form building duties of the SDDNG payment processor.
 *
 * Covers the payment field metadata, the billing block validation and the
 * template variables the processor assigns for CRM/Core/Payment/SEPA/SDD.tpl.
 *
 * @group headless
 */
class CRM_Sepapp_SddngFormTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

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
   * The donor has to provide IBAN and BIC, the rest is optional.
   */
  public function testPaymentFormFieldsMetadata(): void {
    $metadata = $this->getSddngProcessor()->getPaymentFormFieldsMetadata();

    $this->assertTrue((bool) $metadata['bank_account_number']['is_required'], 'IBAN is required');
    $this->assertEquals(34, $metadata['bank_account_number']['attributes']['maxlength'], 'IBAN length');
    $this->assertTrue((bool) $metadata['bank_identification_number']['is_required'], 'BIC is required');
    $this->assertEquals(11, $metadata['bank_identification_number']['attributes']['maxlength'], 'BIC length');
    $this->assertFalse((bool) $metadata['account_holder']['is_required'], 'account holder is optional');
    $this->assertFalse((bool) $metadata['bank_name']['is_required'], 'bank name is optional');
  }

  /**
   * The donor can only pick a collection day the creditor collects on.
   */
  public function testCycleDayOptionsComeFromCreditorSetting(): void {
    $this->setSepaSetting('cycledays', '1,15', $this->creditorId);

    $metadata = $this->getSddngProcessor()->getPaymentFormFieldsMetadata();

    $this->assertEquals(['1' => '1', '15' => '15'], $metadata['cycle_day']['attributes']);
  }

  /**
   * The collection day options fall back to the default creditor.
   */
  public function testMetadataFallsBackToDefaultCreditor(): void {
    $this->setSepaSetting('cycledays', '7', $this->creditorId);
    $orphaned_processor_id = $this->createNgPaymentProcessor([
      'name' => 'SEPA Lastschrift NG with deleted creditor',
      'user_name' => '999999',
    ]);

    $metadata = $this->getSddngProcessor($orphaned_processor_id)->getPaymentFormFieldsMetadata();

    $this->assertEquals(['7' => '7'], $metadata['cycle_day']['attributes']);
  }

  /**
   * The collection day and start date are not offered on the payment form.
   *
   * SDDNG defines its field list in _getPaymentFormFields(), whose leading
   * underscore means it overrides nothing, so the form falls back to core's
   * direct debit fields and the donor cannot choose a collection day - even
   * though the metadata above describes one. Fixing that will make this test
   * fail.
   */
  public function testGetPaymentFormFieldsOmitsCycleDay(): void {
    $fields = $this->getSddngProcessor()->getPaymentFormFields();

    $this->assertEquals([
      'account_holder',
      'bank_account_number',
      'bank_identification_number',
      'bank_name',
    ], $fields);
  }

  /**
   * With a hidden billing block, its fields must not block the payment.
   */
  public function testValidatePaymentInstrumentStripsBillingErrorsWhenHidden(): void {
    $this->setSepaSetting('pp_hide_billing', 1);
    $errors = ['billing_first_name' => 'is required'];

    $this->getSddngProcessor()->validatePaymentInstrument($this->getValidPaymentValues(), $errors);

    $this->assertArrayNotHasKey('billing_first_name', $errors);
  }

  /**
   * With a visible billing block, its fields are validated as usual.
   */
  public function testValidatePaymentInstrumentKeepsBillingErrorsOtherwise(): void {
    $this->setSepaSetting('pp_hide_billing', 0);
    $errors = ['billing_first_name' => 'is required'];

    $this->getSddngProcessor()->validatePaymentInstrument($this->getValidPaymentValues(), $errors);

    $this->assertArrayHasKey('billing_first_name', $errors);
  }

  /**
   * The form learns the earliest dates the creditor can collect on.
   */
  public function testBuildFormAssignsEarliestDates(): void {
    $this->setSepaSetting('batching.OOFF.notice', '3');
    $this->setSepaSetting('batching.FRST.notice', '4');
    $this->setSepaSetting('pp_buffer_days', '2');
    $this->setSepaSetting('cycledays', '15', $this->creditorId);
    $form = new CRM_Core_Form();

    $this->getSddngProcessor()->buildForm($form);

    $this->assertEquals(date('Y-m-d', strtotime('+3 days')), $form->getTemplateVars('earliest_ooff_date'), 'OOFF');
    $this->assertEquals(date('Y-m-d', strtotime('+6 days')), $form->getTemplateVars('earliest_rcur_date'), 'RCUR');
    $this->assertEquals('15', $form->getTemplateVars('earliest_cycle_day'), 'cycle day');
  }

  /**
   * Texts the processor does not know are answered by CiviCRM itself.
   */
  public function testGetTextFallsBackToParentForUnknownContext(): void {
    $text = $this->getSddngProcessor()->getText('contributionPageRecurringHelp', ['is_recur_installments' => TRUE]);

    $this->assertStringContainsString('installments', $text);
  }

  /**
   * @return \CRM_Core_Payment_SDDNG
   *   The processor, set up with a full payment processor record.
   */
  private function getSddngProcessor(?int $processorId = NULL): CRM_Core_Payment_SDDNG {
    $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addWhere('id', '=', $processorId ?? $this->ngPaymentProcessorId)
      ->execute()
      ->single();

    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor($processor);

    return $sddng;
  }

  /**
   * @return array
   *   Submitted values that satisfy the mandatory payment fields.
   */
  private function getValidPaymentValues(): array {
    return [
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::TEST_BIC_VALID,
    ];
  }

}
