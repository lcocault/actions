<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les erreurs journalisées par le code testé ne polluent pas la sortie.
ini_set('error_log', sys_get_temp_dir() . '/actions-tests.log');
