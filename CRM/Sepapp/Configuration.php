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
class CRM_Sepapp_Configuration
{

    const LOG_LEVEL_DEBUG = 0;
    const LOG_LEVEL_AUDIT = 5;
    const LOG_LEVEL_INFO = 10;
    const LOG_LEVEL_ERROR = 20;

    static protected $LOG_LEVEL = self::LOG_LEVEL_DEBUG;

    /**
     * Log messages
     *
     * @param $message  string log message
     * @param $level    int log level, see constants
     */
    public static function log($message, $level = self::LOG_LEVEL_AUDIT)
    {
        if ($level >= self::$LOG_LEVEL) {
            CRM_Core_Error::debug_log_message("SepaPP: {$message}");
        }
    }
}
