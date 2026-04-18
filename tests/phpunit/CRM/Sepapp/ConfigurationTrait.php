<?php

/**
 * Shared configuration for SEPA payment processor tests.
 *
 * This trait provides common setup methods for creating test payment
 * processors and SEPA creditors needed by multiple test classes.
 */
trait CRM_Sepapp_ConfigurationTrait {

  /**
   * Payment processor type ID for SDD Next Generation.
   */
  const PAYMENT_PROCESSOR_TYPE_NG = 10;

  /**
   * Payment processor type ID for SDD Legacy.
   */
  const PAYMENT_PROCESSOR_TYPE_LEGACY = 9;

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
   * Default country ID for German tests.
   */
  const COUNTRY_GERMANY = 1082;

  /**
   * Create basic test configuration.
   *
   * Creates payment processors (NG and Legacy) and a SEPA creditor
   * that can be used by tests.
   *
   * @return int
   *   The ID of the NG payment processor.
   */
  protected function createBasicConfiguration() {
    $pp = [
      'domain_id' => 1,
      'name' => 'SEPA Lastschrift NG',
      'title' => 'SEPA Lastschrift',
      'frontend_title' => 'SEPA Lastschrift',
      'payment_processor_type_id' => self::PAYMENT_PROCESSOR_TYPE_NG,
      'is_active' => TRUE,
      'is_default' => TRUE,
      'is_test' => FALSE,
      'user_name' => '1',
      'class_name' => 'Payment_SDDNG',
      'billing_mode' => 1,
      'is_recur' => TRUE,
      'payment_type' => 2,
      'payment_instrument_id' => 3,
    ];
    $ngProcessor = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues($pp)
      ->execute()
      ->first();
    $ngPaymentProcessorId = $ngProcessor['id'];

    $pp['name'] = 'SEPA Lastschrift';
    $pp['payment_processor_type_id'] = self::PAYMENT_PROCESSOR_TYPE_LEGACY;
    $pp['class_name'] = 'Payment_SDD';
    \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues($pp)
      ->execute()
      ->first();

    \Civi\Api4\SepaCreditor::create(FALSE)->setValues([
        'creditor_id' => 1,
        'identifier' => 'DE02370502990000684712',
        'name' => 'SEPA Lastschrift',
        'label' => 'SEPA Lastschrift',
        'address' => 'Teststraße 1',
        'country_id' => self::COUNTRY_GERMANY,
        'iban' => 'DE02370502990000684712',
        'bic' => 'COKSDE33',
        'mandate_prefix' => 'SEPA',
        'currency' => 'EUR',
        'mandate_active' => TRUE,
        'sepa_file_format_id' => 12,
        'creditor_type' => 'SEPA',
        'pi_ooff' => '7',
        'pi_rcur' => '5-6',
        'uses_bic' => FALSE,
      ])->execute()->first();

    return $ngPaymentProcessorId;
  }

}