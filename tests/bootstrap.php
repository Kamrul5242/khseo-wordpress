<?php
/**
 * Unit-test bootstrap: no WordPress. Only pure classes are tested here.
 *
 * @package KHSEO
 */

declare(strict_types=1);

define( 'KHSEO_TESTING', true );

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
KHSEO\Autoloader::register( dirname( __DIR__ ) . '/src/' );
