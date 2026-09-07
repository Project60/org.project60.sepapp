<?php

use Civi\Test\CiviEnvBuilder;

/**
 * Shared configuration for SEPA payment processor tests.
 *
 * This trait provides the headless setup and the factories for the entities
 * the payment processor tests need: a SEPA creditor, the two payment
 * processors, contacts, contributions and recurring contributions.
 *
 * All IDs are resolved by name, so the tests do not depend on the option value
 * IDs of a particular database.
 */
trait CRM_Sepapp_ConfigurationTrait {

  /**
   * Whether setUpHeadless() should force a rebuild of the test database.
   */
  const FORCE_REBUILD = FALSE;

  /**
   * Test IBAN for valid transactions.
   */
  const TEST_IBAN = 'DE88100900001234567892';

  /**
   * Invalid IBAN for testing rejection.
   */
  const TEST_IBAN_INVALID = 'INVALID123';

  /**
   * Test BIC for valid transactions.
   */
  const TEST_BIC_VALID = 'BEVODEBB';

  /**
   * Invalid BIC for testing rejection.
   */
  const TEST_BIC_INVALID = 'INVALID';

  /**
   * IBAN of the test creditor.
   */
  const CREDITOR_IBAN = 'DE02370502990000684712';

  /**
   * BIC of the test creditor.
   */
  const CREDITOR_BIC = 'COKSDE33';

  /**
   * @var int
   *   The ID of the NG payment processor created by createBasicConfiguration().
   */
  protected $ngPaymentProcessorId;

  /**
   * @var int
   *   The ID of the legacy payment processor created by
   *   createBasicConfiguration().
   */
  protected $legacyPaymentProcessorId;

  /**
   * @var int
   *   The ID of the SEPA creditor created by createBasicConfiguration().
   */
  protected $creditorId;

  /**
   * @var int
   *   The ID of the contact created by createBasicConfiguration().
   */
  protected $testContactId;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      // CiviCampaign is needed to attach campaigns to test contributions.
      ->install(['org.civicrm.search_kit', 'civi_campaign', 'org.project60.sepa'])
      ->installMe(__DIR__)
      ->apply(self::FORCE_REBUILD);
  }

  /**
   * Create basic test configuration.
   *
   * Creates a contact, a SEPA creditor, both payment processors (NG and
   * legacy) and pins the batching settings the collection date calculation
   * depends on.
   *
   * @return int
   *   The ID of the NG payment processor.
   */
  protected function createBasicConfiguration(): int {
    $this->testContactId = $this->createTestContact();
    $this->creditorId = $this->createCreditor()['id'];
    $this->ngPaymentProcessorId = $this->createNgPaymentProcessor();
    $this->legacyPaymentProcessorId = $this->createLegacyPaymentProcessor();

    // Pin the settings that the collection date calculation reads, so tests
    // do not depend on whatever the site happens to be configured with.
    CRM_Sepa_Logic_Settings::setGenericSetting($this->creditorId, 'batching_default_creditor');
    CRM_Sepa_Logic_Settings::setGenericSetting('0', 'pp_buffer_days');
    CRM_Sepa_Logic_Settings::setGenericSetting('0', 'batching.FRST.notice');
    CRM_Sepa_Logic_Settings::setGenericSetting('0', 'batching.OOFF.notice');
    CRM_Sepa_Logic_Settings::setGenericSetting('0', 'batching.RCUR.notice');

    return $this->ngPaymentProcessorId;
  }

  /**
   * Create the SEPA creditor used by the payment processors.
   *
   * @param array $overrides
   *   Values to override the defaults with.
   *
   * @return array
   *   The created creditor.
   */
  protected function createCreditor(array $overrides = []): array {
    $classic_payment_instruments = CRM_Sepa_Logic_PaymentInstruments::getClassicSepaPaymentInstruments();

    $values = array_merge([
      'creditor_id' => $this->getCreditorContactId(),
      'identifier' => self::CREDITOR_IBAN,
      'name' => 'SEPA Lastschrift',
      'label' => 'SEPA Lastschrift',
      'address' => 'Teststraße 1',
      'country_id' => $this->getCountryId('DE'),
      'iban' => self::CREDITOR_IBAN,
      'bic' => self::CREDITOR_BIC,
      'mandate_prefix' => 'SEPA',
      'currency' => 'EUR',
      'mandate_active' => TRUE,
      'sepa_file_format_id' => $this->getSepaFileFormatId('pain.008.001.02'),
      'creditor_type' => 'SEPA',
      'pi_ooff' => $classic_payment_instruments['OOFF'],
      'pi_rcur' => "{$classic_payment_instruments['FRST']}-{$classic_payment_instruments['RCUR']}",
      'uses_bic' => FALSE,
    ], $overrides);

    return \Civi\Api4\SepaCreditor::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();
  }

  /**
   * Create an SDDNG payment processor.
   *
   * @param array $overrides
   *   Values to override the defaults with. Pass 'user_name' to point the
   *   processor at another creditor (or at none at all).
   *
   * @return int
   *   The ID of the created payment processor.
   */
  protected function createNgPaymentProcessor(array $overrides = []): int {
    $values = array_merge($this->getPaymentProcessorDefaults(), [
      'name' => 'SEPA Lastschrift NG',
      'payment_processor_type_id' => $this->getPaymentProcessorTypeId('SEPA_Direct_Debit_NG'),
      'class_name' => 'Payment_SDDNG',
    ], $overrides);

    return \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a legacy SDD payment processor.
   *
   * @param array $overrides
   *   Values to override the defaults with.
   *
   * @return int
   *   The ID of the created payment processor.
   */
  protected function createLegacyPaymentProcessor(array $overrides = []): int {
    $values = array_merge($this->getPaymentProcessorDefaults(), [
      'name' => 'SEPA Lastschrift',
      'payment_processor_type_id' => $this->getPaymentProcessorTypeId('SEPA_Direct_Debit'),
      'class_name' => 'Payment_SDD',
      'is_default' => FALSE,
    ], $overrides);

    return \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a contact to attach test contributions to.
   *
   * @return int
   *   The contact ID.
   */
  protected function createTestContact(): int {
    return \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Sepapp')
      ->addValue('last_name', 'Testcontact')
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a test contribution.
   *
   * @param array $overrides
   *   Values to override the defaults with.
   *
   * @return array
   *   The created contribution.
   */
  protected function createTestContribution(array $overrides = []): array {
    $values = array_merge([
      'contact_id' => $this->testContactId,
      'trxn_id' => 'TEST-TRX-' . md5(microtime() . mt_rand()),
      'receive_date' => '2025-04-01',
      'total_amount' => '100.00',
      'currency' => 'EUR',
      'contribution_source' => 'Sepapp Test',
      'financial_type_id' => $this->getFinancialTypeId('Donation'),
      'payment_instrument_id' => $this->getPaymentInstrumentId('EFT'),
    ], $overrides);

    return \Civi\Api4\Contribution::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();
  }

  /**
   * Create a test campaign.
   *
   * @return array
   *   The created campaign.
   */
  protected function createTestCampaign(): array {
    return \Civi\Api4\Campaign::create(FALSE)
      ->addValue('name', 'sepapp_test_campaign')
      ->addValue('title', 'Sepapp Test Campaign')
      ->execute()
      ->first();
  }

  /**
   * Create a test recurring contribution.
   *
   * @param array $overrides
   *   Values to override the defaults with.
   *
   * @return array
   *   The created recurring contribution.
   */
  protected function createTestContributionRecur(array $overrides = []): array {
    $values = array_merge([
      'contact_id' => $this->testContactId,
      'amount' => '100.00',
      'currency' => 'EUR',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'contribution_status_id' => $this->getContributionStatusId('Pending'),
      'payment_instrument_id' => $this->getPaymentInstrumentId('EFT'),
      'payment_processor_id' => $this->ngPaymentProcessorId,
    ], $overrides);

    return \Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();
  }

  /**
   * Run doDirectPayment() on the SDDNG processor.
   *
   * This is how a mandate-in-the-making gets staged in production, so tests
   * that need pending mandate data should start here rather than poking the
   * static directly.
   *
   * @param array $overrides
   *   Values to override the defaults with.
   *
   * @return array
   *   The parameters as returned by doDirectPayment().
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  protected function startNgPayment(array $overrides = []): array {
    $params = array_merge([
      'payment_processor_id' => $this->ngPaymentProcessorId,
      'bank_account_number' => self::TEST_IBAN,
      'bank_identification_number' => self::TEST_BIC_VALID,
      'contact_id' => $this->testContactId,
      'amount' => 100.00,
      'currency' => 'EUR',
    ], $overrides);

    $sddng = new CRM_Core_Payment_SDDNG();
    $sddng->setPaymentProcessor(['id' => $params['payment_processor_id']]);

    return $sddng->doDirectPayment($params);
  }

  /**
   * Set a SEPA setting, optionally as a creditor specific override.
   *
   * @param string $key
   *   The setting name, e.g. 'cycledays' or 'batching.FRST.notice'.
   * @param mixed $value
   *   The value to set.
   * @param int|null $creditorId
   *   Set an override for this creditor only; NULL sets the generic setting.
   */
  protected function setSepaSetting(string $key, $value, ?int $creditorId = NULL): void {
    CRM_Sepa_Logic_Settings::setSetting($value, $key, $creditorId);
  }

  /**
   * Clear the pending mandate handover state of the SDDNG processor.
   *
   * The processor caches a "mandate in the making" in a protected static, and
   * offers no way to clear it: releasePendingMandateData() bails out without
   * clearing whenever the data is incomplete or the ID does not match. Tests
   * must therefore reset it directly, or they leak state into each other.
   */
  protected function resetPendingMandate(): void {
    $pending_mandate = new ReflectionProperty(CRM_Core_Payment_SDDNG::class, '_pending_mandate');
    $pending_mandate->setAccessible(TRUE);
    $pending_mandate->setValue(NULL, NULL);
  }

  /**
   * Flush the in-memory settings cache.
   *
   * TransactionalInterface rolls back the civicrm_setting rows, but the
   * settings bag keeps the values in memory, so they would leak into the
   * following test.
   */
  protected function flushSettingsCache(): void {
    \Civi\Core\Container::getBootService('settings_manager')->flush();
  }

  /**
   * @return int
   *   The ID of the contribution status with the given name.
   */
  protected function getContributionStatusId(string $name): int {
    return (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $name);
  }

  /**
   * @return int
   *   The ID of the payment instrument with the given name.
   */
  protected function getPaymentInstrumentId(string $name): int {
    return (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', $name);
  }

  /**
   * The default values shared by both payment processors.
   */
  private function getPaymentProcessorDefaults(): array {
    return [
      'domain_id' => 1,
      'title' => 'SEPA Lastschrift',
      'frontend_title' => 'SEPA Lastschrift',
      'is_active' => TRUE,
      'is_default' => TRUE,
      'is_test' => FALSE,
      // For the SDD processors, user_name holds the creditor ID.
      'user_name' => (string) $this->creditorId,
      'billing_mode' => 1,
      'is_recur' => TRUE,
      'payment_type' => CRM_Core_Payment::PAYMENT_TYPE_DIRECT_DEBIT,
      'payment_instrument_id' => $this->getPaymentInstrumentId('RCUR'),
    ];
  }

  /**
   * @return int
   *   The ID of the payment processor type with the given name.
   */
  private function getPaymentProcessorTypeId(string $name): int {
    return (int) \Civi\Api4\PaymentProcessorType::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['id'];
  }

  /**
   * @return int
   *   The ID of the country with the given ISO code.
   */
  private function getCountryId(string $isoCode): int {
    return (int) \Civi\Api4\Country::get(FALSE)
      ->addSelect('id')
      ->addWhere('iso_code', '=', $isoCode)
      ->execute()
      ->single()['id'];
  }

  /**
   * @return int
   *   The value of the sepa_file_format option with the given name.
   */
  private function getSepaFileFormatId(string $name): int {
    return (int) \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'sepa_file_format')
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['value'];
  }

  /**
   * @return int
   *   The ID of the financial type with the given name.
   */
  private function getFinancialTypeId(string $name): int {
    return (int) \Civi\Api4\FinancialType::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['id'];
  }

  /**
   * The organisation the creditor belongs to.
   */
  private function getCreditorContactId(): int {
    return \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Sepapp Test Creditor')
      ->execute()
      ->first()['id'];
  }

}
