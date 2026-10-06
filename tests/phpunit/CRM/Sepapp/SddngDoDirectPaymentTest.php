<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for CRM_Core_Payment_SDDNG::doDirectPayment().
 *
 * The IBAN/BIC validation itself is covered by CRM_Sepapp_EdgeCaseTest; this
 * class covers how the payment parameters are read, how customisations can
 * change them, and the guard against a second payment in the same request.
 *
 * @group headless
 */
class CRM_Sepapp_SddngDoDirectPaymentTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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
   * The bank account fields of the payment form hold the IBAN and BIC.
   */
  public function testIbanIsTakenFromBankAccountNumber(): void {
    $params = $this->startNgPayment([
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::TEST_BIC_VALID,
    ]);

    $this->assertEquals(self::TEST_IBAN, $params['iban'], 'IBAN');
    $this->assertEquals(self::TEST_BIC_VALID, $params['bic'], 'BIC');
  }

  /**
   * A second payment for another contribution is a broken workflow.
   *
   * Only one mandate can be in the making at a time, because it is staged in a
   * request global.
   */
  public function testWorkflowErrorWhenDifferentContributionIsPending(): void {
    $this->startNgPayment(['contributionID' => 4711]);

    $this->expectException(PaymentProcessorException::class);
    $this->startNgPayment(['contributionID' => 4712]);
  }

  /**
   * Repeating the payment for the same contribution is fine.
   */
  public function testSameContributionIdIsAccepted(): void {
    $this->startNgPayment(['contributionID' => 4711]);

    $params = $this->startNgPayment(['contributionID' => 4711]);

    $this->assertEquals(self::TEST_IBAN, $params['iban']);
  }

  /**
   * Customisations can correct the bank details before they are validated.
   */
  public function testAlterPaymentProcessorParamsHookCanFixIban(): void {
    $params = $this->startNgPayment([
      'bank_account_number' => self::TEST_IBAN_INVALID,
      'bank_identification_number' => self::TEST_BIC_VALID,
    ]);

    $this->assertEquals(self::TEST_IBAN, $params['iban']);
  }

  /**
   * Implements hook_civicrm_alterPaymentProcessorParams().
   *
   * Replaces the invalid test IBAN with a valid one, to show that the hook
   * runs before the IBAN is verified.
   */
  public function hook_civicrm_alterPaymentProcessorParams($paymentObj, $rawParams, &$cookedParams): void {
    if (($cookedParams['iban'] ?? NULL) === self::TEST_IBAN_INVALID) {
      $cookedParams['iban'] = self::TEST_IBAN;
    }
  }

}
