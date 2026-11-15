<?php

\Bitrix\Main\Loader::registerAutoLoadClasses('msp.oos', [
    'Msp\\Oos\\Options' => 'lib/options.php',
    'Msp\\Oos\\Log' => 'lib/log.php',
    'Msp\\Oos\\Token' => 'lib/token.php',
    'Msp\\Oos\\Discovery' => 'lib/discovery.php',
    'Msp\\Oos\\ProofKey' => 'lib/proofkey.php',
    'Msp\\Oos\\LockTable' => 'lib/locktable.php',
    'Msp\\Oos\\LockManager' => 'lib/lockmanager.php',
    'Msp\\Oos\\DiskFile' => 'lib/diskfile.php',
    'Msp\\Oos\\WopiServer' => 'lib/wopiserver.php',
    'Msp\\Oos\\HostPage' => 'lib/hostpage.php',
]);
