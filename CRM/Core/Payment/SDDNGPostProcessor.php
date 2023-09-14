<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit payment processor       |
| Copyright (C) 2018-2020 SYSTOPIA                       |
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

include_once "api/Wrapper.php";

/**
 * SEPA_Direct_Debit payment processor
 *
 * This API Wrapper extends the Contribution.completetransaction
 *  API call triggered after a successful contribution
 *
 * @package CiviCRM_SEPA
 */
class CRM_Core_Payment_SDDNGPostProcessor implements API_Wrapper
{
    /**
     * Nothing to do here, we'll execute _after_ the original call
     */
    public function fromApiInput($apiRequest)
    {
        return $apiRequest;
    }

    /**
     * alter the result before returning it to the caller.
     */
    public function toApiOutput($apiRequest, $result)
    {
        // before returning this, we'll do our wrap-up as well:
        if (!empty($apiRequest['params']['id'])) {
            self::createPendingMandate($apiRequest['params']['id']);
        }
        // nothing to do here
        return $result;
    }

    /**
     * Create a pending mandate for a contribution created by a SDD payment processor
     *
     * @param $contribution_id integer   the freshly created contribution
     * @todo: react on component
     * @todo: remove financial transactions
     *
     * @todo: recurring contributions
     */
    public static function createPendingMandate($contribution_id = null)
    {
        CRM_Sepapp_Configuration::log(
            "createPendingMandate for contribution ID [{$contribution_id}]",
            CRM_Sepapp_Configuration::LOG_LEVEL_DEBUG
        );

        // fall back to current ID
        if ($contribution_id == null) {
            $contribution_id = CRM_Core_Payment_SDDNG::getPendingContributionID();
        }

        // get pending mandate data (and mark as processed)
        $params = CRM_Core_Payment_SDDNG::releasePendingMandateData($contribution_id);
        if (!$params) {
            // nothing pending for us...
            CRM_Sepapp_Configuration::log(
                "createPendingMandate had nothing to do",
                CRM_Sepapp_Configuration::LOG_LEVEL_DEBUG
            );
            return;
        }

        // CREATE OOFF CONTRIBUTION
        // load contribution
        $contribution = civicrm_api3('Contribution', 'getsingle', array('id' => $contribution_id));

        // load payment processor
        $payment_processor = civicrm_api3(
            'PaymentProcessor',
            'getsingle',
            array(
                'id'     => $params['payment_processor_id'],
                'return' => 'user_name'
            )
        );

        // load creditor
        $creditor_id = (int)CRM_Utils_Array::value('user_name', $payment_processor);
        if (!$creditor_id) {
            CRM_Sepapp_Configuration::log(
                "No creditor found for PaymentProcessor [{$payment_processor['id']}]",
                CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
            );
            return;
        }
        $creditor = civicrm_api3('SepaCreditor', 'get', array('id' => $creditor_id));

        if (empty($params['contributionRecurID'])) {
            // OOFF Donation:
            // create mandate
            CRM_Sepapp_Configuration::log(
                "createPendingMandate creating OOFF mandate",
                CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
            );
            $mandate = civicrm_api3(
                'SepaMandate',
                'create',
                array(
                    'creditor_id'     => $creditor['id'],
                    'type'            => 'OOFF',
                    'iban'            => $params['iban'],
                    'bic'             => $params['bic'],
                    'status'          => 'OOFF',
                    'entity_table'    => 'civicrm_contribution',
                    'entity_id'       => $contribution_id,
                    'contact_id'      => $contribution['contact_id'],
                    'campaign_id'     => CRM_Utils_Array::value('campaign_id', $contribution),
                    'currency'        => CRM_Utils_Array::value('currency', $creditor, 'EUR'),
                    'date'            => date('YmdHis'),
                    'creation_date'   => date('YmdHis'),
                    'validation_date' => date('YmdHis'),
                    'source'          => substr($contribution['contribution_source'], 0, 64),
                )
            );
            CRM_Sepapp_Configuration::log(
                "OOFF mandate [{$mandate['id']}] created.",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );

            // reset contribution to 'Pending'
            $ooff_payment = (int)CRM_Core_PseudoConstant::getKey(
                'CRM_Contribute_BAO_Contribution',
                'payment_instrument_id',
                'OOFF'
            );
            self::resetContribution($contribution_id, $ooff_payment);
            CRM_Sepapp_Configuration::log(
                "Contribution [{$contribution_id}] adjusted.",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );
        } else {
            // RECURRING DONATION
            // create mandate
            CRM_Sepapp_Configuration::log(
                "createPendingMandate creating RCUR mandate",
                CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
            );
            $mandate = civicrm_api3(
                'SepaMandate',
                'create',
                array(
                    'creditor_id'     => $creditor['id'],
                    'type'            => 'RCUR',
                    'iban'            => $params['iban'],
                    'bic'             => $params['bic'],
                    'status'          => 'FRST',
                    'entity_table'    => 'civicrm_contribution_recur',
                    'entity_id'       => $params['contributionRecurID'],
                    'contact_id'      => $contribution['contact_id'],
                    'campaign_id'     => CRM_Utils_Array::value('campaign_id', $contribution),
                    'currency'        => CRM_Utils_Array::value('currency', $creditor, 'EUR'),
                    'date'            => date('YmdHis'),
                    'creation_date'   => date('YmdHis'),
                    'validation_date' => date('YmdHis'),
                    'source'          => substr($contribution['contribution_source'], 0, 64),
                )
            );
            CRM_Sepapp_Configuration::log(
                "RCUR mandate [{$mandate['id']}] created.",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );

            // reset recurring contribution
            self::updateRecurringContribution($params, $creditor['id'], $contribution);
            CRM_Sepapp_Configuration::log(
                "Recurring contribution [{$params['contributionRecurID']}] adjusted.",
                CRM_Sepapp_Configuration::LOG_LEVEL_AUDIT
            );

            // finally: delete contribution
            civicrm_api3('Contribution', 'delete', ['id' => $contribution_id]);
        }
    }

    /**
     * Tries to undo some of the stuff done to the contribution
     *
     * @param $contribution_id       int Contribution ID
     * @param $payment_instrument_id int Payment Instrument to set
     */
    public static function resetContribution($contribution_id, $payment_instrument_id)
    {
        CRM_Sepapp_Configuration::log(
            "resetContribution [{$contribution_id}/{$payment_instrument_id}]",
            CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
        );
        // update contribution... this can be tricky
        $status_pending = (int)CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Pending'
        );
        try {
            civicrm_api3(
                'Contribution',
                'create',
                array(
                    'skipRecentView'         => 1, // avoid overhead
                    'id'                     => $contribution_id,
                    'contribution_status_id' => $status_pending,
                    'payment_instrument_id'  => $payment_instrument_id,
                )
            );
        } catch (Exception $ex) {
            // that's not good... but we can't leave it like this...
            $error_message = $ex->getMessage();
            CRM_Sepapp_Configuration::log(
                "SDD reset contribution via API failed ('{$error_message}'), using SQL...",
                CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
            );
            CRM_Core_DAO::executeQuery(
                "UPDATE civicrm_contribution SET contribution_status_id = %1, payment_instrument_id = %2 WHERE id = %3;",
                array(
                    1 => array($status_pending, 'Integer'),
                    2 => array($payment_instrument_id, 'Integer'),
                    3 => array($contribution_id, 'Integer')
                )
            );
        }

        // delete all finacial transactions
        CRM_Core_DAO::executeQuery(
            "
      DELETE FROM civicrm_financial_trxn
      WHERE id IN (SELECT etx.financial_trxn_id 
                   FROM civicrm_entity_financial_trxn etx 
                   WHERE etx.entity_id = {$contribution_id}
                     AND etx.entity_table = 'civicrm_contribution');"
        );
    }

    /**
     * Tries to undo some of the stuff done to the recurring contribution
     *
     * @param $contribution_recur_id int   ContributionRecur ID
     * @param $contribution          array the individual contribution
     * @param $payment_instrument_id int   Payment Instrument to set
     */
    public static function updateRecurringContribution($params, $creditor, $contribution)
    {
        CRM_Sepapp_Configuration::log(
            "resetContribution [{$contribution_id}/{$payment_instrument_id}]",
            CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
        );

        // calculate start_date
        $start_date = self::getNextPossibleCollectionDate($creditor['id']);

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
        $contribution_recur_id = (int)$params['contributionRecurID'];
        $status_pending        = (int)CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Pending'
        );
        $payment_instrument_id = (int)CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'payment_instrument_id',
            'RCUR'
        );

        try {
            civicrm_api3(
                'ContributionRecur',
                'create',
                array(
                    'skipRecentView'         => 1, // avoid overhead
                    'id'                     => $contribution_recur_id,
                    'contribution_status_id' => $status_pending,
                    'payment_instrument_id'  => $payment_instrument_id,
                    'start_date'             => $start_date,
                    'cycle_day'              => date('j', strtotime($start_date)),
                    'end_date'               => $end_date
                )
            );
        } catch (Exception $ex) {
            // that's not good... but we can't leave it like this...
            $error_message = $ex->getMessage();
            CRM_Sepapp_Configuration::log(
                "SDD reset contribution via API failed ('{$error_message}'), using SQL...",
                CRM_Sepapp_Configuration::LOG_LEVEL_ERROR
            );
            CRM_Core_DAO::executeQuery(
                "UPDATE civicrm_contribution_recur SET contribution_status_id = %1, payment_instrument_id = %2 WHERE id = %3;",
                array(
                    1 => array($status_pending, 'Integer'),
                    2 => array($payment_instrument_id, 'Integer'),
                    3 => array($contribution_recur_id, 'Integer')
                )
            );
        }
    }

    /**
     * Calculate the next possible collection date, based solely on the creditor ID
     *
     * @param $creditor_id
     *
     * @return date
     */
    public static function getNextPossibleCollectionDate($creditor_id, $now = 'now')
    {
        $buffer_days      = (int)CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
        $frst_notice_days = (int)CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor_id);
        $cycle_days       = CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor_id);

        $earliest_date = strtotime("+{$buffer_days} days +{$frst_notice_days} days", strtotime($now));
        while (!in_array(date('j', $earliest_date), $cycle_days)) {
            $earliest_date = strtotime("+ 1 day", $earliest_date);
        }

        return date('Y-m-d', $earliest_date);
    }
}
