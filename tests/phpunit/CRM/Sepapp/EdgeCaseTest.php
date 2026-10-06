<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Edge case tests for SEPA payment processors.
 *
 * Tests various edge cases and error conditions for the SEPA direct debit
 * payment processors (both SDD and SDDNG).
 *
 * @group headless
 */
class CRM_Sepapp_EdgeCaseTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

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

  /**
   * Test that invalid IBAN is rejected.
   */
  public function testInvalidIBANRejected(): void {
    $this->expectException(PaymentProcessorException::class);

    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor(['id' => $this->ngPaymentProcessorId]);

    $params = [
      'iban' => self::TEST_IBAN_INVALID,
      'bic' => 'TESTBIC1',
      'bank_account_number' => self::TEST_IBAN_INVALID,
      'bank_identification_number' => 'TESTBIC1',
      'contact_id' => $this->testContactId,
      'amount' => 100.00,
      'currency' => 'EUR',
    ];

    $sddng->doDirectPayment($params);
  }

  /**
   * Test that invalid BIC is rejected.
   */
  public function testInvalidBICRejected(): void {
    $this->expectException(PaymentProcessorException::class);

    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor(['id' => $this->ngPaymentProcessorId]);

    $params = [
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_INVALID,
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::TEST_BIC_INVALID,
      'contact_id' => $this->testContactId,
      'amount' => 100.00,
      'currency' => 'EUR',
    ];

    $sddng->doDirectPayment($params);
  }

  /**
   * Test valid IBAN and BIC are accepted.
   */
  public function testValidIBANAndBICAccepted(): void {
    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor(['id' => $this->ngPaymentProcessorId]);

    $params = [
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::TEST_BIC_VALID,
      'contact_id' => $this->testContactId,
      'amount' => 100.00,
      'currency' => 'EUR',
    ];

    $result = $sddng->doDirectPayment($params);
    $this->assertArrayHasKey('iban', $result);
    $this->assertArrayHasKey('bic', $result);
    $this->assertEquals(self::TEST_IBAN, $result['iban']);
    $this->assertEquals(self::TEST_BIC_VALID, $result['bic']);
  }

  /**
   * Test that pending mandate data is properly stored and retrieved.
   */
  public function testPendingMandateDataStorage(): void {
    $this->startNgPayment();

    $contributionId = 998;
    $testData = [
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'contribution_id' => $contributionId,
    ];

    CRM_Core_Payment_SDDNG::setPendingMandateData($testData);

    $storedData = CRM_Core_Payment_SDDNG::releasePendingMandateData($contributionId);

    $this->assertNotNull($storedData);
    $this->assertEquals($this->ngPaymentProcessorId, $storedData['payment_processor_id']);
    $this->assertEquals(self::TEST_IBAN, $storedData['iban']);
    $this->assertEquals(self::TEST_BIC_VALID, $storedData['bic']);
    $this->assertEquals($contributionId, $storedData['contribution_id']);
  }

  /**
   * Test that pending mandate data returns null with mismatched contribution ID.
   */
  public function testPendingMandateDataMismatchedId(): void {
    $this->startNgPayment();

    $contributionId = 998;
    $wrongId = 888;
    $testData = [
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'contribution_id' => $contributionId,
    ];

    CRM_Core_Payment_SDDNG::setPendingMandateData($testData);

    $storedData = CRM_Core_Payment_SDDNG::releasePendingMandateData($wrongId);

    $this->assertNull($storedData);
  }

  /**
   * Test that getPendingContributionID returns null when no contribution is set.
   */
  public function testGetPendingContributionIDNoData(): void {
    $contributionId = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertNull($contributionId);
  }

  /**
   * Test that the payment processor type name is correct.
   */
  public function testPaymentProcessorTypeName(): void {
    $sddng = new CRM_Core_Payment_SDDNG();
    $typeName = $sddng->getPaymentTypeName();
    $this->assertEquals('direct_debit_ng', $typeName);
  }

  /**
   * Test that the payment processor type label is correct.
   */
  public function testPaymentProcessorTypeLabel(): void {
    $sddng = new CRM_Core_Payment_SDDNG();
    $typeLabel = $sddng->getPaymentTypeLabel();
    $this->assertEquals('Direct Debit', $typeLabel);
  }

  /**
   * Test that checkConfig returns null (no configuration errors).
   */
  public function testCheckConfig(): void {
    $sddng = new CRM_Core_Payment_SDDNG();
    $configError = $sddng->checkConfig();
    $this->assertNull($configError);
  }

  /**
   * Test that getText returns expected values for different contexts.
   */
  public function testGetText(): void {
    $sddng = new CRM_Core_Payment_SDDNG();

    $agreementTitle = $sddng->getText('agreementTitle', []);
    $this->assertEquals('Agreement', $agreementTitle);

    $agreementText = $sddng->getText('agreementText', []);
    $this->assertStringContainsString('direct debit', $agreementText);
    $this->assertStringContainsString('bank account', $agreementText);
  }

  /**
   * Test the processContribution method with pending mandate data.
   */
  public function testProcessContributionWithPendingMandate(): void {
    $this->startNgPayment();

    $contributionId = 555;
    $testData = [
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => self::TEST_BIC_VALID,
      'contribution_id' => $contributionId,
    ];

    CRM_Core_Payment_SDDNG::setPendingMandateData($testData);
    CRM_Core_Payment_SDDNG::processContribution($contributionId);

    $pendingId = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertEquals($contributionId, $pendingId);
  }

  /**
   * Test that setPendingContributionID correctly sets the contribution ID.
   */
  public function testSetPendingContributionID(): void {
    $contributionId = 777;

    CRM_Core_Payment_SDDNG::setPendingContributionID($contributionId);

    $pendingId = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertEquals($contributionId, $pendingId);
  }

}
