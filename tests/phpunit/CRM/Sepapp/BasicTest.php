<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Basic tests for the SDDNG payment processor.
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

  public function testSimpleNG(): void {
    $res = $this->createTestContribution(['contribution_source' => 'TEST 3']);

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
    $this->assertEquals('TEST 3', $sepaMandates['source']);
  }

}
