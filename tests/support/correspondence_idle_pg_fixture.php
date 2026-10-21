<?php
declare(strict_types=1);
if(!defined('PGSQL_TRANSACTION_IDLE'))define('PGSQL_TRANSACTION_IDLE',0);
if(!function_exists('pg_transaction_status')){
    function pg_transaction_status(mixed $connection):int{return PGSQL_TRANSACTION_IDLE;}
}
