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

use CRM_Sepapp_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Sepapp_Upgrader extends CRM_Extension_Upgrader_Base
{
    /**
     * Module is enabled
     */
    public function enable()
    {
        // check if any payment processors need to be revived
        $this->revivedSDDPaymentProcessors();
    }

    /**
     * Module is disabled
     */
    public function disable()
    {
        // check if any payment processors need to be revived
        $this->suspendSDDPaymentProcessors();
    }

    /**
     * Try to revive SDD payment processors, that have been suspended,
     *  e.g. by an upgrade of CiviSEPA version >= 1.5
     */
    protected function revivedSDDPaymentProcessors()
    {
        // INSTALL OLD PROCESSOR
        $sdd_pp_type_ids = [];
        $sdd_pp          = civicrm_api3('PaymentProcessorType', 'get', array('name' => PP_SDD_PROCESSOR_TYPE));
        if (empty($sdd_pp['id'])) {
            // doesn't exist yet => create
            $payment_processor_data         = array(
                "name"                   => "SEPA_Direct_Debit",
                "title"                  => E::ts("SEPA Direct Debit"),
                "description"            => E::ts("Payment processor for the 'Single European Payment Area' (SEPA)."),
                "is_active"              => 1,
                "user_name_label"        => "SEPA Creditor identifier",
                "class_name"             => "Payment_SDD",
                "url_site_default"       => "",
                "url_recur_default"      => "",
                "url_site_test_default"  => "",
                "url_recur_test_default" => "",
                "billing_mode"           => "1",
                "is_recur"               => "1",
                "payment_type"           => CRM_Core_Payment::PAYMENT_TYPE_DIRECT_DEBIT
            );
            $result                         = civicrm_api3('PaymentProcessorType', 'create', $payment_processor_data);
            $sdd_pp_type_ids[$result['id']] = 'Payment_SDD';
            CRM_Sepapp_Configuration::log(
                "org.project60.sepa_dd: created payment processor with name PP_SDD_PROCESSOR_TYPE",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );
        } else {
            // already exists => enable if not enabled
            $sdd_pp_type_ids[$sdd_pp['id']] = 'Payment_SDD';
            if (empty($sdd_pp['is_active'])) {
                $result = civicrm_api3(
                    'PaymentProcessorType',
                    'create',
                    array(
                        'id'        => $sdd_pp['id'],
                        'is_active' => 1
                    )
                );
            }
        }

        // INSTALL NEW/NG PROCESSOR
        $sdd_pp_ng = civicrm_api3('PaymentProcessorType', 'get', array('name' => PP_SDD_PROCESSOR_TYPE_NEW));
        if (empty($sdd_pp_ng['id'])) {
            // doesn't exist yet => create
            $payment_processor_data         = array(
                "name"                   => PP_SDD_PROCESSOR_TYPE_NEW,
                "title"                  => E::ts("SEPA Direct Debit (NEW)"),
                "description"            => E::ts(
                    "Refactored Payment processor for the 'Single European Payement Area' (SEPA)."
                ),
                "is_active"              => 1,
                "user_name_label"        => "SEPA Creditor identifier",
                "class_name"             => "Payment_SDDNG",
                "url_site_default"       => "",
                "url_recur_default"      => "",
                "url_site_test_default"  => "",
                "url_recur_test_default" => "",
                "billing_mode"           => "1",
                "is_recur"               => "1",
                "payment_type"           => CRM_Core_Payment::PAYMENT_TYPE_DIRECT_DEBIT
            );
            $result                         = civicrm_api3('PaymentProcessorType', 'create', $payment_processor_data);
            $sdd_pp_type_ids[$result['id']] = 'Payment_SDDNG';
            CRM_Sepapp_Configuration::log(
                "org.project60.sepa_dd: created payment processor with name 'SEPA_Direct_Debit_NG'",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );
        } else {
            // already exists => enable if not enabled
            $sdd_pp_type_ids[$sdd_pp_ng['id']] = 'Payment_SDDNG';
            if (empty($sdd_pp_ng['is_active'])) {
                $result = civicrm_api3(
                    'PaymentProcessorType',
                    'create',
                    array(
                        'id'        => $sdd_pp_ng['id'],
                        'is_active' => 1
                    )
                );
            }
        }

        // restore dummy instances
        if (!empty($sdd_pp_type_ids)) {
            $sdd_pps = civicrm_api3(
                'PaymentProcessor',
                'get',
                [
                    'payment_processor_type_id' => ['IN' => array_keys($sdd_pp_type_ids)],
                    'class_name'                => 'Payment_Dummy'
                ]
            );
            foreach ($sdd_pps['values'] as $sdd_pp) {
                civicrm_api3(
                    'PaymentProcessor',
                    'create',
                    [
                        'id'         => $sdd_pp['id'],
                        'class_name' => $sdd_pp_type_ids[$sdd_pp['payment_processor_type_id']]
                    ]
                );
            }
        }
    }

    /**
     * Suspend payment processors, as the implementation code is
     *   no longer available
     */
    protected function suspendSDDPaymentProcessors()
    {
        // get the IDs of the SDD processor types
        $sdd_processor_type_ids = [];
        $sdd_processor_type_query = civicrm_api3('PaymentProcessorType', 'get', [
            'name'         => ['IN' => ['SEPA_Direct_Debit', 'SEPA_Direct_Debit_NG']],
            'return'       => 'id',
            'option.limit' => 0,
        ]);
        foreach ($sdd_processor_type_query['values'] as $pp_type) {
            $sdd_processor_type_ids[] = (int) $pp_type['id'];
        }

        // if there is SDD types registered (which should be the case), we have to deal with them
        if (!empty($sdd_processor_type_ids)) {
            // find out, if they're being used
            $sdd_processor_type_id_list = implode(',', $sdd_processor_type_ids);
            $use_count = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_payment_processor WHERE payment_processor_type_id IN ({$sdd_processor_type_id_list});");

            if ($use_count) {
                // if the payment processors are being used, divert them to the dummy processor
                //  and issue a warning to install the SDD PP extension
                $message = E::ts("Your CiviSEPA payment processors have been disabled.");
                CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor SET class_name='Payment_Dummy' WHERE payment_processor_type_id IN ({$sdd_processor_type_id_list});");
                CRM_Core_Session::setStatus($message, E::ts("%1 Payment Processor(s) Disabled!", [1 => $use_count]), 'warn');
                CRM_Sepapp_Configuration::log(
                    $message,
                    CRM_Sepapp_Configuration::LOG_LEVEL_INFO
                );
            } else {
                // if they are _not_ used, we can simply delete them.
                foreach ($sdd_processor_type_ids as $sdd_processor_type_id) {
                    civicrm_api3('PaymentProcessorType', 'delete', ['id' => $sdd_processor_type_id]);
                }
            }
        }
    }

}
