<?php

use CRM_Sepapp_ExtensionUtil as E;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

use CRM_Core_Payment_SDD;

/**
 * FIXME - Add test description.
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
  const FORCE_REBUILD = FALSE;

  const TEST_IBAN = "DE88100900001234567892";

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install(['org.project60.sepa'])
      ->installMe(__DIR__)
      ->apply(self::FORCE_REBUILD);
  }

  public function setUp():void {
    parent::setUp();

    #$this->createBasicConfiguration();
  }

  public function createBasicConfiguration()
  {
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
    $paymentProcessors = \Civi\Api4\PaymentProcessor::create(FALSE)->setValues($pp)->execute()->first();
    #dump($paymentProcessors);

    $pp['name'] = "SEPA Lastschrift";
    $pp['payment_processor_type_id'] = 9; // legacy
    $pp['class_name'] = "Payment_SDD";
    $paymentProcessors = \Civi\Api4\PaymentProcessor::create(FALSE)->setValues($pp)->execute()->first();
    #dump($paymentProcessors);

    $sepaCreditor = \Civi\Api4\SepaCreditor::create(FALSE)->setValues(
      [
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
      ]
    )->execute()->first();
    #dump($sepaCreditor);
  }

  public function tearDown():void {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testWellFormedVersion():void {
    $this->assertNotEmpty(E::SHORT_NAME);
    $this->assertMatchesRegularExpression('/^([0-9\.]|alpha|beta)*$/', \CRM_Utils_System::version());
  }

  /**
   * Example: Test that we're using a fake CMS.
   */
  public function testWellFormedUF():void {
    $this->assertEquals('UnitTests', CIVICRM_UF);
  }

  public function testSimpleNG(): void {
    $test_id = 3;
    $res = $this->createTestContribution($test_id);

    $id = CRM_Core_Payment_SDDNG::getPendingContributionID();
    $this->assertEquals($res['id'], $id, "CRM_Core_Payment_SDDNG::getPendingContributionID");

    // set test data
    CRM_Core_Payment_SDDNG::setPendingMandateData(
      [
        'payment_processor_id' => 1,
        'iban' => self::TEST_IBAN,
        'bic' => "BEVODEBB",
      ]
    );

    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    $sepaMandates = \Civi\Api4\SepaMandate::get(FALSE)
      ->addSelect('*')
      ->execute()->first();
    $this->assertNotEmpty($sepaMandates);
    $this->assertEquals(self::TEST_IBAN, $sepaMandates['iban']);
    $this->assertEquals('TEST '. $test_id, $sepaMandates['source']);
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


