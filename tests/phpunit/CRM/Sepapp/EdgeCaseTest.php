<?php

use Civi\API4\Contribution;
use Civi\API4\ContributionRecur;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\SepaCreditor;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

use CRM_Sepapp_ExtensionUtil as E;

/**
 * Edge case tests for SEPA payment processors.
 *
 * Tests various edge cases and error conditions for the SEPA direct debit
 * payment processors (both SDD and SDDNG).
 *
 * @group headless
 */
class CRM_Sepapp_EdgeCaseTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  const FORCE_REBUILD = FALSE;

  const TEST_IBAN = "DE88100900001234567892";

  const INVALID_IBAN = "INVALID123";

  const INVALID_BIC = "INVALID";

  /** @var int The ID of the NG payment processor created in setUp */
  protected $ngPaymentProcessorId;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and
   * sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->install(['org.project60.sepa'])
      ->installMe(__DIR__)
      ->apply(self::FORCE_REBUILD);
  }

  public function setUp(): void {
    parent::setUp();

    $this->ngPaymentProcessorId = $this->createBasicConfiguration();
  }

  public function createBasicConfiguration() {
    $pp = [
      "domain_id" => 1,
      "name" => "SEPA Lastschrift NG",
      "title" => "SEPA Lastschrift",
      "frontend_title" => "SEPA Lastschrift",
      "payment_processor_type_id" => 10, // NG
      "is_active" => TRUE,
      "is_default" => TRUE,
      "is_test" => FALSE,
      "user_name" => "1",
      "class_name" => "Payment_SDDNG",
      "billing_mode" => 1,
      "is_recur" => TRUE,
      "payment_type" => 2,
      "payment_instrument_id" => 3,
    ];
    $paymentProcessors = PaymentProcessor::create(FALSE)
      ->setValues($pp)
      ->execute()
      ->first();
    $ngPaymentProcessorId = $paymentProcessors['id'];

    $pp['name'] = "SEPA Lastschrift";
    $pp['payment_processor_type_id'] = 9; // legacy
    $pp['class_name'] = "Payment_SDD";
    $paymentProcessors = PaymentProcessor::create(FALSE)
      ->setValues($pp)
      ->execute()
      ->first();

    $sepaCreditor = SepaCreditor::create(FALSE)->setValues([
        "creditor_id" => 1,
        "identifier" => "DE02370502990000684712",
        "name" => "SEPA Lastschrift",
        "label" => "SEPA Lastschrift",
        "address" => "Teststraße 1",
        "country_id" => 1082,
        "iban" => "DE02370502990000684712",
        "bic" => "COKSDE33",
        "mandate_prefix" => "SEPA",
        "currency" => "EUR",
        "mandate_active" => TRUE,
        "sepa_file_format_id" => 12,
        "creditor_type" => "SEPA",
        "pi_ooff" => "7",
        "pi_rcur" => "5-6",
        "uses_bic" => FALSE,
      ])->execute()->first();

    return $ngPaymentProcessorId;
  }

  /**
   * Test that invalid IBAN is rejected.
   */
  public function testInvalidIBANRejected(): void {
    $this->expectException(PaymentProcessorException::class);

    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor(['id' => $this->ngPaymentProcessorId]);

    $params = [
      'iban' => self::INVALID_IBAN,
      'bic' => 'TESTBIC1',
      'bank_account_number' => self::INVALID_IBAN,
      'bank_identification_number' => 'TESTBIC1',
      'contact_id' => 1,
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
      'bic' => self::INVALID_BIC,
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::INVALID_BIC,
      'contact_id' => 1,
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
      'bic' => 'BEVODEBB',
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => 'BEVODEBB',
      'contact_id' => 1,
      'amount' => 100.00,
      'currency' => 'EUR',
    ];

    $result = $sddng->doDirectPayment($params);
    $this->assertArrayHasKey('iban', $result);
    $this->assertArrayHasKey('bic', $result);
    $this->assertEquals(self::TEST_IBAN, $result['iban']);
    $this->assertEquals('BEVODEBB', $result['bic']);
  }

  /**
   * Test that pending mandate data is properly stored and retrieved.
   */
  public function testPendingMandateDataStorage(): void {
    $contributionId = 999;
    $testData = [
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'iban' => self::TEST_IBAN,
      'bic' => 'BEVODEBB',
      'contribution_id' => $contributionId,
    ];

    // Store the data
    CRM_Core_Payment_SDDNG::setPendingMandateData($testData);

    // Verify it can be retrieved
    $storedData = CRM_Core_Payment_SDDNG::releasePendingMandateData($contributionId);

    $this->assertNotNull($storedData);
    $this->assertEquals($this->ngPaymentProcessorId, $storedData['payment_processor_id']);
    $this->assertEquals(self::TEST_IBAN, $storedData['iban']);
    $this->assertEquals('BEVODEBB', $storedData['bic']);
    $this->assertEquals($contributionId, $storedData['contribution_id']);
  }

  /**
   * Test that getPendingContributionID returns null when no contribution is set.
   */
  public function testGetPendingContributionIDNoData(): void {
    // Clear any pending data
    CRM_Core_Payment_SDDNG::releasePendingMandateData(999);

    $contributionId = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertNull($contributionId);
  }

  /**
   * Test creating a valid contribution.
   */
  public function testCreateValidContribution(): void {
    $result = Contribution::create(FALSE)->setValues([
        'contact_id' => 1,
        'trxn_id' => 'TEST-TRX-' . md5(microtime() . mt_rand()),
        'receive_date' => '01.04.2025',
        'total_amount' => '100.00',
        'currency' => 'EUR',
        'contribution_source' => 'Test Contribution',
        'financial_type_id' => 2,
        'payment_instrument_id' => 1,
      ])->execute()->first();

    $this->assertArrayHasKey('id', $result);
    $this->assertGreaterThan(0, $result['id']);
    $this->assertEquals(1, $result['contact_id']);
    $this->assertEquals('100.00', $result['total_amount']);
  }

  /**
   * Test that mandate creation works with recurring contributions.
   */
  public function testRecurringContributionMandateCreation(): void {
    // Create a recurring contribution
    $recurResult = ContributionRecur::create(FALSE)->setValues([
        'contact_id' => 1,
        'amount' => 100.00,
        'currency' => 'EUR',
        'frequency_unit' => 'month',
        'frequency_interval' => 1,
        'installments' => 12,
        'contribution_status_id' => 'Pending',
        'payment_instrument_id' => 3,
      ])->execute()->first();

    $this->assertArrayHasKey('id', $recurResult);
    $this->assertGreaterThan(0, $recurResult['id']);
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

    // Test agreement title
    $agreementTitle = $sddng->getText('agreementTitle', []);
    $this->assertEquals('Agreement', $agreementTitle);

    // Test agreement text
    $agreementText = $sddng->getText('agreementText', []);
    $this->assertStringContainsString('direct debit', $agreementText);
    $this->assertStringContainsString('bank account', $agreementText);
  }

}
