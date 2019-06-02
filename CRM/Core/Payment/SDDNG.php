<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit                         |
| Copyright (C) 2018 SYSTOPIA                            |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Sepa_ExtensionUtil as E;

/**
 * SEPA_Direct_Debit payment processor
 *
 * This is a refactored version of CRM_Core_Payment_SDD, see SEPA-498
 *
 * @package CiviCRM_SEPA
 */

class CRM_Core_Payment_SDDNG extends CRM_Core_Payment {

  use CRM_Core_Payment_SDDTrait;

  /** Caches the creditor involved */
  protected $_creditor = NULL;



  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentTypeName() {
    return 'direct_debit_ng';
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentTypeLabel() {
    return E::ts('Direct Debit');
  }

  /**
   * Should the first payment date be configurable when setting up back office recurring payments.
   * In the case of Authorize.net this is an option
   * @return bool
   */
  protected function supportsFutureRecurStartDate() {
    return FALSE;
  }

  /**
   * Can recurring contributions be set against pledges.
   *
   * In practice all processors that use the baseIPN function to finish transactions or
   * call the completetransaction api support this by looking up previous contributions in the
   * series and, if there is a prior contribution against a pledge, and the pledge is not complete,
   * adding the new payment to the pledge.
   *
   * However, only enabling for processors it has been tested against.
   *
   * @return bool
   */
  protected function supportsRecurContributionsForPledges() {
    return TRUE;
  }


  /**
   * Submit a payment using Advanced Integration Method.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   *   the result in a nice formatted array (or an error object)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    CRM_Sepapp_Configuration::log("doDirectPayment called: " . json_encode($params), CRM_Sepapp_Configuration::LOG_LEVEL_DEBUG);
    $original_parameters = $params;

    // extract SEPA data
    $params['iban']      = $params['bank_account_number'];
    $params['bic']       = $params['bank_identification_number'];

    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $original_parameters, $params);

    // verify IBAN
    $bad_iban = CRM_Sepa_Logic_Verification::verifyIBAN($params['iban']);
    if ($bad_iban) {
      CRM_Sepapp_Configuration::log("IBAN issue: {$bad_iban}");
      throw new \Civi\Payment\Exception\PaymentProcessorException($bad_iban);
    }

    // verify BIC
    $bad_bic  = CRM_Sepa_Logic_Verification::verifyBIC($params['bic']);
    if ($bad_bic) {
      CRM_Sepapp_Configuration::log("BIC issue: {$bad_bic}");
      throw new \Civi\Payment\Exception\PaymentProcessorException($bad_bic);
    }

    // load payment processor
    $payment_processor = civicrm_api3('PaymentProcessor', 'getsingle', [
        'id'     => $params['payment_processor_id'],
        'return' => 'user_name']);

    // get creditor
    $creditor_id = (int) CRM_Utils_Array::value('user_name', $payment_processor);
    if (!$creditor_id) {
      throw new Exception("No creditor found for PaymentProcessor [{$payment_processor['id']}]");
    }
    $creditor = civicrm_api3('SepaCreditor', 'get', ['id' => $creditor_id]);

    // create the mandate and link
    if (empty($params['is_recur'])) {
      // OOFF Mandate
      $contribution_id = $this->getContributionId($params);
      if (!$contribution_id) {
        throw new Exception("No contribution found");
      }
      CRM_Sepapp_Configuration::log("createPendingMandate creating OOFF mandate", CRM_Sepapp_Configuration::LOG_LEVEL_ERROR);
      $mandate = civicrm_api3('SepaMandate', 'create', [
          'creditor_id'     => $creditor['id'],
          'type'            => 'OOFF',
          'iban'            => $params['iban'],
          'bic'             => $params['bic'],
          'status'          => 'OOFF',
          'entity_table'    => 'civicrm_contribution',
          'entity_id'       => $contribution_id,
          'contact_id'      => $this->getContactId($params),
          //'campaign_id'     => CRM_Utils_Array::value('campaign_id', $contribution),
          'currency'        => CRM_Utils_Array::value('currency', $creditor, 'EUR'),
          'date'            => date('YmdHis'),
          'creation_date'   => date('YmdHis'),
          'validation_date' => date('YmdHis'),
          'source'          => CRM_Utils_Array::value('description', $params, ''),
      ]);
      CRM_Sepapp_Configuration::log("OOFF mandate [{$mandate['id']}] created.", CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT);

      // reset contribution to 'Pending'
      $ooff_payment = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'OOFF');
      $this->resetContribution($contribution_id, $ooff_payment);
      CRM_Sepapp_Configuration::log("Contribution [{$contribution_id}] adjusted.", CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT);

    } else {
      // RCUR Mandate
      $recurring_contribution_id = $this->getRecurringContributionId($params);
      if ($recurring_contribution_id) {
        // create and connect the mandate
        $mandate = civicrm_api3('SepaMandate', 'create', [
            'creditor_id'     => $creditor['id'],
            'type'            => 'RCUR',
            'iban'            => $params['iban'],
            'bic'             => $params['bic'],
            'status'          => 'FRST',
            'entity_table'    => 'civicrm_contribution_recur',
            'entity_id'       => $recurring_contribution_id,
            'contact_id'      => $this->getContactId($params),
            //'campaign_id'     => CRM_Utils_Array::value('campaign_id', $contribution),
            'currency'        => CRM_Utils_Array::value('currency', $creditor, 'EUR'),
            'date'            => date('YmdHis'),
            'creation_date'   => date('YmdHis'),
            'validation_date' => date('YmdHis'),
            'source'          => $contribution['contribution_source'],
        ]);
        CRM_Sepapp_Configuration::log("RCUR mandate [{$mandate['id']}] created and linked.", CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT);


      } else {
        // create a completely new mandate
        $mandate = civicrm_api3('SepaMandate', 'createfull', [
            'creditor_id'        => $creditor['id'],
            'amount'             => $params['amount'],
            'type'               => 'RCUR',
            'iban'               => $params['iban'],
            'bic'                => $params['bic'],
            'contact_id'         => $this->getContactId($params),
            // 'campaign_id'     => CRM_Utils_Array::value('campaign_id', $contribution),
            'currency'           => CRM_Utils_Array::value('currency', $creditor, 'EUR'),
            'frequency_unit'     => $params['frequency_unit'],
            'frequency_interval' => $params['frequency_interval'],
            'financial_type_id'  => $params['financial_type_id'],
            'date'               => date('YmdHis'),
            'creation_date'      => date('YmdHis'),
            'validation_date'    => date('YmdHis'),
            'source'             => CRM_Utils_Array::value('description', $params, ''),
        ]);
      }
      CRM_Sepapp_Configuration::log("RCUR mandate [{$mandate['id']}] created.", CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT);

      // reset recurring contribution
      $this->updateRecurringContribution($params, $creditor['id']);
      CRM_Sepapp_Configuration::log("Recurring contribution [{$params['contributionRecurID']}] adjusted.", CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT);

      // finally: delete contribution
      $contribution_id = $this->getContributionId($params);
      if ($contribution_id) {
        civicrm_api3('Contribution', 'delete', ['id' => $contribution_id]);
      }
    }

    // report that all is fine
    $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');
    $params['payment_status_id'] = array_search('Pending', $statuses);
    return $params;
  }


  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    // TODO: anything to check?
    return NULL;
  }


  /****************************************************************************
   *                           Helpers                                        *
   ****************************************************************************/

  /**
   * Get the creditor currently involved in the process
   *
   * @return array|void
   */
  protected function getCreditor() {
    if (!$this->_creditor) {
      $pp = $this->getPaymentProcessor();
      $creditor_id = $pp['user_name'];
      try {
        $this->_creditor = civicrm_api3('SepaCreditor', 'getsingle', ['id' => $creditor_id]);
      } catch (Exception $ex) {
        // probably no creditor set, or creditor has been deleted - use default
        CRM_Sepapp_Configuration::log("Creditor [{$creditor_id}] not found, SDDNG using default/any", CRM_Sepapp_Configuration::LOG_LEVEL_ERROR);
        $default_creditor_id = (int) CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');
        $creditors = civicrm_api3('SepaCreditor', 'get', ['id' => $default_creditor_id]);
        $this->_creditor = reset($creditors['values']);
      }
    }
    return $this->_creditor;
  }

  /**
   * Tries to undo some of the stuff done to the contribution
   *
   * @param $contribution_id       int Contribution ID
   * @param $payment_instrument_id int Payment Instrument to set
   */
  protected function resetContribution($contribution_id, $payment_instrument_id) {
    CRM_Sepapp_Configuration::log("resetContribution [{$contribution_id}/{$payment_instrument_id}]", CRM_Sepapp_Configuration::LOG_LEVEL_ERROR);
    // update contribution... this can be tricky
    $status_pending = (int)CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    try {
      civicrm_api3('Contribution', 'create', [
          'skipRecentView'         => 1, // avoid overhead
          'id'                     => $contribution_id,
          'contribution_status_id' => $status_pending,
          'payment_instrument_id'  => $payment_instrument_id,
      ]);
    } catch (Exception $ex) {
      // that's not good... but we can't leave it like this...
      $error_message = $ex->getMessage();
      CRM_Sepapp_Configuration::log("SDD reset contribution via API failed ('{$error_message}'), using SQL...", CRM_Sepapp_Configuration::LOG_LEVEL_ERROR);
      CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution SET contribution_status_id = %1, payment_instrument_id = %2 WHERE id = %3;", [
          1 => [$status_pending,        'Integer'],
          2 => [$payment_instrument_id, 'Integer'],
          3 => [$contribution_id,       'Integer']]);
    }

    // delete all finacial transactions
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_financial_trxn
      WHERE id IN (SELECT etx.financial_trxn_id 
                   FROM civicrm_entity_financial_trxn etx 
                   WHERE etx.entity_id = {$contribution_id}
                     AND etx.entity_table = 'civicrm_contribution');");
  }

  /**
   * Tries to undo some of the stuff done to the recurring contribution
   *
   * @param $contribution_recur_id int   ContributionRecur ID
   * @param $contribution          array the individual contribution
   * @param $payment_instrument_id int   Payment Instrument to set
   */
  protected function updateRecurringContribution($params, $creditor) {
    // calculate start_date
    $start_date = $this->getNextPossibleCollectionDate($creditor['id']);

    // calculate installments if requested
    if (!empty($params['installments'])) {
      // start with the start date (hopefully first collection)
      $end_date = strtotime($start_date);
      for ($i = 0; $i < $params['installments']; $i++) {
        // skip forward one cycle per installment
        $end_date = strtotime("+{$params['frequency_interval']} {$params['frequency_unit']}", $end_date);
      }
      // since this is "one too many", move back 5 days and format
      $end_date = date('Y-m-d', strtotime("-5 days", $end_date));

    } else {
      $end_date = '';
    }


    // update recurring contribution
    $contribution_recur_id = (int) $params['contributionRecurID'];
    $status_pending        = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $payment_instrument_id = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'RCUR');

    try {
      civicrm_api3('ContributionRecur', 'create', [
          'skipRecentView'         => 1, // avoid overhead
          'id'                     => $contribution_recur_id,
          'contribution_status_id' => $status_pending,
          'payment_instrument_id'  => $payment_instrument_id,
          'start_date'             => $start_date,
          'end_date'               => $end_date]);
    } catch (Exception $ex) {
      // that's not good... but we can't leave it like this...
      $error_message = $ex->getMessage();
      CRM_Sepapp_Configuration::log("SDD reset contribution via API failed ('{$error_message}'), using SQL...", CRM_Sepapp_Configuration::LOG_LEVEL_ERROR);
      CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur SET contribution_status_id = %1, payment_instrument_id = %2 WHERE id = %3;", [
          1 => [$status_pending,        'Integer'],
          2 => [$payment_instrument_id, 'Integer'],
          3 => [$contribution_recur_id, 'Integer']]);
    }
  }

  /**
   * Calculate the next possible collection date, based solely on the creditor ID
   *
   * @param $creditor_id
   *
   * @return date
   */
  protected function getNextPossibleCollectionDate($creditor_id, $now = 'now') {
    $buffer_days      = (int) CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
    $frst_notice_days = (int) CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor_id);
    $cycle_days       = CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor_id);

    $earliest_date = strtotime("+{$buffer_days} days +{$frst_notice_days} days", strtotime($now));
    while (!in_array(date('j', $earliest_date), $cycle_days)) {
      $earliest_date = strtotime("+ 1 day", $earliest_date);
    }

    return date('Y-m-d', $earliest_date);
  }




  /***********************************************
   *            Form-building duty               *
   ***********************************************/

  function buildForm(&$form) {
    // add rules
    $form->registerRule('sepa_iban_valid', 'callback', 'rule_valid_IBAN', 'CRM_Sepa_Logic_Verification');
    $form->registerRule('sepa_bic_valid',  'callback', 'rule_valid_BIC',  'CRM_Sepa_Logic_Verification');

    // BUFFER DAYS / TODO: MOVE TO SERVICE
    $creditor = $this->getCreditor();
    $buffer_days      = (int) CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
    $frst_notice_days = (int) CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor['id']);
    $ooff_notice_days = (int) CRM_Sepa_Logic_Settings::getSetting("batching.OOFF.notice", $creditor['id']);
    $earliest_rcur_date = strtotime("now + $frst_notice_days days + $buffer_days days");
    $earliest_ooff_date = strtotime("now + $ooff_notice_days days");

    // find the next cycle day
    $cycle_days = CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor['id']);
    $earliest_cycle_day = $earliest_rcur_date;
    while (!in_array(date('j', $earliest_cycle_day), $cycle_days)) {
      $earliest_cycle_day = strtotime("+ 1 day", $earliest_cycle_day);
    }

    $form->assign('earliest_rcur_date', date('Y-m-d', $earliest_rcur_date));
    $form->assign('earliest_ooff_date', date('Y-m-d', $earliest_ooff_date));
    $form->assign('earliest_cycle_day', date('j', $earliest_cycle_day));
    $form->assign('sepa_hide_bic', CRM_Sepa_Logic_Settings::getSetting("pp_hide_bic"));
    $form->assign('sepa_hide_billing', CRM_Sepa_Logic_Settings::getSetting("pp_hide_billing"));
    $form->assign('bic_extension_installed', CRM_Sepa_Logic_Settings::isLittleBicExtensionAccessible());

    CRM_Core_Region::instance('billing-block')->add(
        ['template' => 'CRM/Core/Payment/SEPA/SDD.tpl', 'weight' => -1]);
  }

  /**
   * Override custom PI validation
   *  to make billing information NOT mandatory (see SEPA-372)
   *
   * @author N. Bochan
   */
  public function validatePaymentInstrument($values, &$errors) {
    // first: call parent's implementation
    parent::validatePaymentInstrument($values, $errors);

    // if this feature is not active, we do nothing:
    $pp_hide_billing = CRM_Sepa_Logic_Settings::getSetting("pp_hide_billing");
    if (empty($pp_hide_billing)) return;

    // now: by removing all the errors on the billing fields, we
    //   effectively render the billing block "not mandatory"
    if (isset($errors)) {
      foreach ($errors as $fieldname => $error_message) {
        if (substr($fieldname, 0, 8) == 'billing_') {
          unset($errors[$fieldname]);
        }
      }
    }
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function _getPaymentFormFields() {
    return [
        'cycle_day',
        'start_date',
        'account_holder',
        'bank_account_number',
        'bank_identification_number',
        'bank_name',
    ];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $creditor = $this->getCreditor();
    return [
        'account_holder'             => [
            'htmlType'    => 'text',
            'name'        => 'account_holder',
            'title'       => E::ts('Account Holder'),
            'cc_field'    => TRUE,
            'attributes'  => [
                'size'         => 20,
                'maxlength'    => 34,
                'autocomplete' => 'on',
            ],
            'is_required' => FALSE,
        ],
        //e.g. IBAN can have maxlength of 34 digits
        'bank_account_number'        => [
            'htmlType'    => 'text',
            'name'        => 'bank_account_number',
            'default'     => 'DE91100000000123456789',
            'title'       => E::ts('IBAN'),
            'cc_field'    => TRUE,
            'attributes'  => [
                'size'         => 34,
                'maxlength'    => 34,
                'autocomplete' => 'off',
            ],
            'rules'       => [
                [
                    'rule_message'    => E::ts('This is not a correct IBAN.'),
                    'rule_name'       => 'sepa_iban_valid',
                    'rule_parameters' => NULL,
                ],
            ],
            'is_required' => TRUE,
        ],
        //e.g. SWIFT-BIC can have maxlength of 11 digits
        'bank_identification_number' => [
            'htmlType'    => 'text',
            'name'        => 'bank_identification_number',
            'title'       => E::ts('BIC'),
            'cc_field'    => TRUE,
            'attributes'  => [
                'size'         => 20,
                'maxlength'    => 11,
                'autocomplete' => 'off',
            ],
            'is_required' => TRUE,
            'rules'       => [
                [
                    'rule_message'    => E::ts('This is not a correct BIC.'),
                    'rule_name'       => 'sepa_bic_valid',
                    'rule_parameters' => NULL,
                ],
            ],
        ],
        'bank_name'                  => [
            'htmlType'    => 'text',
            'name'        => 'bank_name',
            'title'       => E::ts('Bank Name'),
            'cc_field'    => TRUE,
            'attributes'  => [
                'size'         => 34,
                'maxlength'    => 64,
                'autocomplete' => 'off',
            ],
            'is_required' => FALSE,
        ],
        'cycle_day'                  => [
            'htmlType'    => 'select',
            'name'        => 'cycle_day',
            'title'       => E::ts('Collection Day'),
            'cc_field'    => TRUE,
            'attributes'  => CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor['id']),
            'is_required' => FALSE,
        ],
        'start_date'                 => [
            'htmlType'    => 'text',
            'name'        => 'start_date',
            'title'       => E::ts('Start Date'),
            'cc_field'    => TRUE,
            'attributes'  => [],
            'is_required' => TRUE,
            'rules'       => [],
        ]
    ];
  }
}
