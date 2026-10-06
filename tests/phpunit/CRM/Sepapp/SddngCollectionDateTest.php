<?php

require_once dirname(__DIR__) . '/Sepapp/ConfigurationTrait.php';

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the collection date calculation of the SDDNG post processor.
 *
 * CRM_Core_Payment_SDDNGPostProcessor::getNextPossibleCollectionDate() takes
 * the "current" date as a parameter, so these tests can pin it down instead of
 * depending on the day they run on.
 *
 * @group headless
 */
class CRM_Sepapp_SddngCollectionDateTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

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
   * The collection date has to be one of the creditor's cycle days.
   */
  public function testLandsOnAllowedCycleDay(): void {
    $this->setSepaSetting('cycledays', '15', $this->creditorId);

    $collection_date = CRM_Core_Payment_SDDNGPostProcessor::getNextPossibleCollectionDate($this->creditorId, '2026-01-01');

    $this->assertEquals('2026-01-15', $collection_date);
  }

  /**
   * Buffer days and the FRST notice period both delay the collection.
   */
  public function testRespectsNoticeAndBufferDays(): void {
    $this->setSepaSetting('pp_buffer_days', '2');
    $this->setSepaSetting('batching.FRST.notice', '5');

    $collection_date = CRM_Core_Payment_SDDNGPostProcessor::getNextPossibleCollectionDate($this->creditorId, '2026-01-01');

    $this->assertEquals('2026-01-08', $collection_date);
  }

  /**
   * If no cycle day is left this month, we collect in the next one.
   */
  public function testRollsOverIntoNextMonth(): void {
    $this->setSepaSetting('cycledays', '1', $this->creditorId);

    $collection_date = CRM_Core_Payment_SDDNGPostProcessor::getNextPossibleCollectionDate($this->creditorId, '2026-01-05');

    $this->assertEquals('2026-02-01', $collection_date);
  }

}
