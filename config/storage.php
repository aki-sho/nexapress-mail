<?php
return [
    'private_directory' => defined('BASE_PATH')
        ? BASE_PATH . '/storage/private/nexapress-mail'
        : sys_get_temp_dir() . '/nexapress-mail',
];
