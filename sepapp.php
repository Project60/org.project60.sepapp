<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit payment processor       |
| Copyright (C) 2020 SYSTOPIA                            |
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

require_once 'sepapp.civix.php';

use CRM_Sepapp_ExtensionUtil as E;


/**
 * Provides the SEPA payment processor
 *
 * @package CiviCRM_SEPA
 *
 */

define('PP_SDD_PROCESSOR_TYPE', 'SEPA_Direct_Debit');
define('PP_SDD_PROCESSOR_TYPE_NEW', 'SEPA_Direct_Debit_NG');

/**
 * buildForm Hook for payment processor
 */
function sepapp_civicrm_buildForm($formName, &$form)
{
    if ($formName == "CRM_Admin_Form_PaymentProcessor") {                    // PAYMENT PROCESSOR CONFIGURATION PAGE
        // get payment processor id
        $pp_id      = $form->getVar('_id');
        $pp_type_id = $form->getVar('_paymentProcessorType');
        if ($pp_id || $pp_type_id) {
            // check if its ours (looking into pp or pp_type:
            $pp_class_name = '';
            if ($pp_id) {
                $pp            = civicrm_api3("PaymentProcessor", "getsingle", array("id" => $pp_id));
                $pp_class_name = $pp['class_name'];
            } else {
                $pp_type       = civicrm_api3("PaymentProcessorType", "getsingle", array("id" => $pp_type_id));
                $pp_class_name = $pp_type['class_name'];
            }

            if ($pp_class_name == "Payment_SDD" || $pp_class_name == "Payment_SDDNG") {
                // it's ours!

                // find the associated creditor(s)
                $creditor_id      = null;
                $test_creditor_id = null;

                $pp_creditor      = null;
                $test_pp_creditor = null;

                if (!empty($pp_id)) {
                    $creditor_id      = CRM_Core_BAO_Setting::getItem('SEPA Direct Debit PP', 'pp' . $pp_id);
                    $test_creditor_id = CRM_Core_BAO_Setting::getItem('SEPA Direct Debit PP', 'pp_test' . $pp_id);
                }

                // load settings from creditor
                if ($creditor_id) {
                    $pp_creditor = civicrm_api3('SepaCreditor', 'getsingle', array('id' => $creditor_id));
                }
                if ($test_creditor_id) {
                    $test_pp_creditor = civicrm_api3('SepaCreditor', 'getsingle', array('id' => $test_creditor_id));
                }

                $creditors = civicrm_api3('SepaCreditor', 'get');
                $creditors = $creditors['values'];

                $test_creditors = civicrm_api3('SepaCreditor', 'get', array('category' => 'TEST'));
                if (empty($test_creditors['values'])) {
                    // no test creditors? just offer the regular ones, selecting none is not good
                    $test_creditors = civicrm_api3('SepaCreditor', 'get');
                }
                $test_creditors = $test_creditors['values'];

                // use settings
                if ($pp_creditor) {
                    $form->assign('user_name', $creditor_id);
                }
                if ($test_pp_creditor) {
                    $form->assign('test_user_name', $test_creditor_id);
                }
                $form->assign('creditors', $creditors);
                $form->assign('test_creditors', $test_creditors);

                // add new elements
                CRM_Core_Region::instance('page-body')->add(
                    array(
                        'template' => 'CRM/Admin/Form/PaymentProcessor/SDD.tpl'
                    )
                );
            }
        }
    } elseif ($formName == "CRM_Contribute_Form_Contribution_Main") {                          // PAYMENT PROCESS MAIN PAGE
        $mendForm = CRM_Core_BAO_Setting::getItem('SEPA Direct Debit Preferences', 'pp_improve_frequency');
        if ($mendForm) {
            // inject improved form logic
            CRM_Core_Region::instance('page-body')->add(
                array(
                    'template' => 'CRM/Contribute/Form/ContributionMain.sepa.tpl'
                )
            );
        }
    } elseif ($formName == "CRM_Contribute_Form_Contribution_Confirm") {                    // PAYMENT PROCESS CONFIRMATION PAGE
        // check if the PP is ours
        $pp_id = CRM_Utils_Array::value('payment_processor', $form->_params);
        if (empty($pp_id)) {
            // there is no payment processor?
            return;
        } else {
            $pp = civicrm_api3('PaymentProcessor', 'getsingle', array('id' => $pp_id));
            if (empty($pp['class_name']) || $pp['class_name'] != 'Payment_SDD') {
                // this is not our processor
                return;
            }
        }

        // this IS our processor -> inject stuff
        CRM_Core_Region::instance('page-body')->add(
            array(
                'template' => 'CRM/Contribute/Form/ContributionConfirm.sepa.tpl'
            )
        );
    } elseif ($formName == "CRM_Event_Form_Registration_Confirm") {                          // EVENT REGISTRATION CONFIRMATION PAGE
        // only for our SDD payment processors:
        $pp = $form->getTemplate()->get_template_vars('paymentProcessor');
        if ($pp['class_name'] != "Payment_SDD") {
            return;
        }

        // FIXME: this is a gross hack, please help me if you know
        //    how to extract bank_bic and bank_iban variables properly...
        $form_data = print_r($form, true);
        $matches   = array();
        if (preg_match(
            '/\[bank_identification_number\] => (?P<bank_identification_number>[\w0-9]+)/i',
            $form_data,
            $matches
        )) {
            $form->assign("bank_identification_number", $matches[1]);
        }
        $matches = array();
        if (preg_match('/\[bank_account_number\] => (?P<bank_account_number>[\w0-9]+)/i', $form_data, $matches)) {
            $form->assign("bank_account_number", $matches[1]);
        }
        unset($form_data);

        CRM_Core_Region::instance('page-body')->add(
            array(
                'template' => 'CRM/Event/Form/RegistrationConfirm.sepa.tpl'
            )
        );
    } elseif ($formName == "CRM_Contribute_Form_Contribution_ThankYou") {                    // PAYMENT PROCESS THANK YOU PAGE
        // check if the PP is ours
        $pp_id = CRM_Utils_Array::value('payment_processor', $form->_params);
        if (empty($pp_id)) {
            // there is no payment processor?
            return;
        } else {
            $pp = civicrm_api3('PaymentProcessor', 'getsingle', array('id' => $pp_id));
            if (empty($pp['class_name']) || $pp['class_name'] != 'Payment_SDD') {
                // this is not our processor
                return;
            }
        }

        // this IS ours
        $mandate_reference = $form->getTemplate()->get_template_vars('trxn_id');
        if ($mandate_reference) {
            $mandate       = civicrm_api3('SepaMandate', 'getsingle', array('reference' => $mandate_reference));
            $creditor      = civicrm_api3('SepaCreditor', 'getsingle', array('id' => $mandate['creditor_id']));
            $contribution  = civicrm_api3('Contribution', 'getsingle', array('trxn_id' => $mandate_reference));
            $rcontribution = array(
                'cycle_day'          => CRM_Utils_Array::value('cycle_day', $form->_params),
                'frequency_interval' => CRM_Utils_Array::value('frequency_interval', $form->_params),
                'frequency_unit'     => CRM_Utils_Array::value('frequency_unit', $form->_params),
                'start_date'         => CRM_Utils_Array::value('start_date', $form->_params)
            );

            $form->assign('mandate_reference', $mandate_reference);
            $form->assign("bank_account_number", $mandate["iban"]);
            $form->assign("bank_identification_number", $mandate["bic"]);
            $form->assign("collection_day", CRM_Utils_Array::value('cycle_day', $form->_params));
            $form->assign("frequency_interval", CRM_Utils_Array::value('frequency_interval', $form->_params));
            $form->assign("frequency_unit", CRM_Utils_Array::value('frequency_unit', $form->_params));
            $form->assign("creditor_id", $creditor['identifier']);
            $form->assign("collection_date", $contribution['receive_date']);
            $form->assign("cycle", CRM_Sepa_Logic_Batching::getCycle($rcontribution));
            $form->assign("cycle_day", CRM_Sepa_Logic_Batching::getCycleDay($rcontribution, $creditor['id']));
        }

        CRM_Core_Region::instance('contribution-thankyou-billing-block')->add(
            array(
                'template' => 'CRM/Contribute/Form/ContributionThankYou.sepa.tpl'
            )
        );
    } elseif ($formName == "CRM_Event_Form_Registration_ThankYou") {                        // EVENT REGISTRATION THANK YOU PAGE
        // only for our SDD payment processors:
        $pp = $form->getTemplate()->get_template_vars('paymentProcessor');
        if ($pp['class_name'] != "Payment_SDD") {
            return;
        }

        $mandate_reference = $form->getTemplate()->get_template_vars('trxn_id');
        if ($mandate_reference) {
            $mandate      = civicrm_api3('SepaMandate', 'getsingle', array('reference' => $mandate_reference));
            $creditor     = civicrm_api3('SepaCreditor', 'getsingle', array('id' => $mandate['creditor_id']));
            $contribution = civicrm_api3('Contribution', 'getsingle', array('trxn_id' => $mandate_reference));
            $form->assign('mandate_reference', $mandate_reference);
            $form->assign("bank_account_number", $mandate["iban"]);
            $form->assign("bank_identification_number", $mandate["bic"]);
            $form->assign("creditor_id", $creditor['identifier']);
            $form->assign("collection_date", $contribution['receive_date']);
        }

        CRM_Core_Region::instance('page-body')->add(
            array(
                'template' => 'CRM/Event/Form/RegistrationThankYou.sepa.tpl'
            )
        );
    }
}

/**
 * postProcess Hook for payment processor
 * (old approach)
 */
function sepapp_civicrm_postProcess($formName, &$form)
{
    // SDD: make sure mandate is created:
    CRM_Core_Payment_SDDNGPostProcessor::createPendingMandate();

    // also: check payment processor
    if ("CRM_Admin_Form_PaymentProcessor" == $formName) {
        $pp_id = $form->getVar('_id');
        if ($pp_id) {
            try {
                $pp = civicrm_api3("PaymentProcessor", "getsingle", array("id" => $pp_id));
                if ($pp['class_name'] == "Payment_SDD" || $pp['class_name'] == 'Payment_SDDNG') {
                    $paymentProcessor = civicrm_api3(
                        'PaymentProcessor',
                        'getsingle',
                        array('name' => $pp['name'], 'is_test' => 0)
                    );

                    $creditor_id      = $form->_submitValues['user_name'];
                    $test_creditor_id = $form->_submitValues['test_user_name'];
                    $pp_id            = $paymentProcessor['id'];

                    // save settings
                    // FIXME: we might consider saving this as a JSON object
                    CRM_Core_BAO_Setting::setItem($creditor_id, 'SEPA Direct Debit PP', 'pp' . $pp_id);
                    CRM_Core_BAO_Setting::setItem($test_creditor_id, 'SEPA Direct Debit PP', 'pp_test' . $pp_id);
                }
            } catch (Exception $ex) {
                throw new Exception("Couldn't find PaymentProcessorType [{$pp_id}]");
            }
        }
    } elseif ('CRM_Contribute_Form_Contribution_Confirm' == $formName) {
        // post process the contributions created
        CRM_Core_Payment_SDD::processPartialMandates();
    } elseif ('CRM_Event_Form_Registration_Confirm' == $formName) {
        // post process the contributions created
        CRM_Core_Payment_SDD::processPartialMandates();
    }
}

/**
 * Will install the SEPA payment processor
 */
function sepapp_civicrm_enable()
{
    _sepapp_civix_civicrm_enable();
}

/**
 * Will disable the SEPA payment processor
 */
function sepapp_civicrm_disable()
{
    _sepapp_civix_civicrm_disable();

    // get payment processor...
    $type_ids     = [];
    $sdd_pp_types = civicrm_api3(
        'PaymentProcessorType',
        'get',
        array(
            'name' => ['IN' => [PP_SDD_PROCESSOR_TYPE, PP_SDD_PROCESSOR_TYPE_NEW]]
        )
    );
    foreach ($sdd_pp_types['values'] as $sdd_pp_type) {
        if ($sdd_pp_type['is_active']) {
            $type_ids[] = $sdd_pp_type['id'];
            $result     = civicrm_api3(
                'PaymentProcessorType',
                'create',
                array(
                    'id'        => $sdd_pp_type['id'],
                    'is_active' => 0
                )
            );
        }
    }

    // set instances to dummy
    if (!empty($type_ids)) {
        $sdd_pps = civicrm_api3(
            'PaymentProcessor',
            'get',
            [
                'payment_processor_type_id' => ['IN' => $type_ids]
            ]
        );
        foreach ($sdd_pps['values'] as $sdd_pp) {
            civicrm_api3(
                'PaymentProcessor',
                'create',
                [
                    'id'         => $sdd_pp['id'],
                    'class_name' => 'Payment_Dummy'
                ]
            );
        }
    }
}

/**
 * Implements hook_civicrm_apiWrappers to prevent people from deleting
 *   contributions connected to SDD mandates.
 */
function sepapp_civicrm_apiWrappers(&$wrappers, $apiRequest)
{
    // add a wrapper for the payment processor
    if ($apiRequest['entity'] == 'Contribution' && $apiRequest['action'] == 'completetransaction') {
        $wrappers[] = new CRM_Core_Payment_SDDNGPostProcessor();
    }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function sepapp_civicrm_config(&$config)
{
    _sepapp_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function sepapp_civicrm_install()
{
    _sepapp_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function sepapp_civicrm_postInstall()
{
    _sepapp_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function sepapp_civicrm_uninstall()
{
    _sepapp_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function sepapp_civicrm_upgrade($op, CRM_Queue_Queue $queue = null)
{
    return _sepapp_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function sepapp_civicrm_entityTypes(&$entityTypes)
{
    _sepapp_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
 * function sepapp_civicrm_preProcess($formName, &$form) {
 *
 * } // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
 * function sepapp_civicrm_navigationMenu(&$menu) {
 * _sepapp_civix_insert_navigation_menu($menu, 'Mailings', array(
 * 'label' => E::ts('New subliminal message'),
 * 'name' => 'mailing_subliminal_message',
 * 'url' => 'civicrm/mailing/subliminal',
 * 'permission' => 'access CiviMail',
 * 'operator' => 'OR',
 * 'separator' => 0,
 * ));
 * _sepapp_civix_navigationMenu($menu);
 * } // */
