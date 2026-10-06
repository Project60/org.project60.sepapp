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


/**
 * SEPA_Direct_Debit payment processor configuration
 */
class CRM_Sepapp_Configuration {

  const LOG_LEVEL_DEBUG = 0;
  const LOG_LEVEL_AUDIT = 5;
  const LOG_LEVEL_INFO = 10;
  const LOG_LEVEL_ERROR = 20;

  static protected $LOG_LEVEL = self::LOG_LEVEL_AUDIT;

  /**
   * Should event participants linked to an SDD contribution be reset
   *  to 'Pending from pay later'?
   *
   * The setting is stored per (live) payment processor, defaults to FALSE.
   *
   * @param $pp_id int payment processor ID (live or test)
   *
   * @return bool
   */
  public static function resetParticipantsEnabled($pp_id) {
    $live_pp_id = self::getLivePaymentProcessorID($pp_id);
    if (!$live_pp_id) {
      return FALSE;
    }
    return (bool) Civi::settings()->get('pp_reset_participants' . $live_pp_id);
  }

  /**
   * Get the ID of the live payment processor matching the given (live or test)
   *  payment processor, since the settings are stored with the live one.
   *
   * @param $pp_id int payment processor ID (live or test)
   *
   * @return int|null
   */
  public static function getLivePaymentProcessorID($pp_id) {
    try {
      $pp = civicrm_api3('PaymentProcessor', 'getsingle', [
        'id' => (int) $pp_id,
        'return' => 'name,is_test',
      ]);
      if (empty($pp['is_test'])) {
        return (int) $pp['id'];
      }
      $live_pp = civicrm_api3('PaymentProcessor', 'getsingle', [
        'name' => $pp['name'],
        'is_test' => 0,
        'return' => 'id',
      ]);
      return (int) $live_pp['id'];
    }
    catch (Exception $ex) {
      return NULL;
    }
  }

  /**
   * Log messages
   *
   * @param $message  string log message
   * @param $level    int log level, see constants
   */
  public static function log($message, $level = self::LOG_LEVEL_AUDIT) {
    if ($level >= self::$LOG_LEVEL) {
      if ($level == self::LOG_LEVEL_ERROR) {
        Civi::log()->error("SepaPP: " . $message);
      }
      else {
        Civi::log()->debug("SepaPP: " . $message);
      }
    }
  }

}
