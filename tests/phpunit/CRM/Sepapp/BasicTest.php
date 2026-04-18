<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

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

  use CRM_Sepapp_ConfigurationTrait;

  const FORCE_REBUILD = FALSE;

  /** @var int The ID of the NG payment processor created in setUp */
  protected $ngPaymentProcessorId;

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

  public function setUp(): void {
    parent::setUp();

    $this->ngPaymentProcessorId = $this->createBasicConfiguration();
  }

  /**
   * Clean up static state after each test.
   */
  public function tearDown(): void {
    CRM_Core_Payment_SDDNG::releasePendingMandateData(999);
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testWellFormedVersion(): void {
    $this->assertNotEmpty(\CRM_Sepapp_ExtensionUtil::SHORT_NAME);
    $this->assertMatchesRegularExpression('/^([0-9\.]|alpha|beta)*$/', \CRM_Utils_System::version());
  }

  /**
   * Example: Test that we're using a fake CMS.
   */
  public function testWellFormedUF(): void {
    $this->assertEquals('UnitTests', CIVICRM_UF);
  }

  public function testSimpleNG(): void {
    $test_id = 3;
    $res = $this->createTestContribution($test_id);

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
    $this->assertEquals('TEST ' . $test_id, $sepaMandates['source']);
  }

  public function createTestContribution(string $name): array {
    return \Civi\API4\Contribution::create(FALSE)->setValues([
        'contact_id' => 1,
        'trxn_id' => 'TEST-TRX-' . $name . '-' . md5(microtime() . mt_rand()),
        'receive_date' => '01.04.2025',
        'total_amount' => '100.00',
        'currency' => 'EUR',
        'contribution_source' => 'TEST ' . $name,
        'financial_type_id' => 2,
        'payment_instrument_id' => 1,
      ])->execute()->first();
  }

}