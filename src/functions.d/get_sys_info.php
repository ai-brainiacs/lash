<?php
$this->functionHandlers['get_sys_info'] = function ($args) {
    if ($this->ash->debug) echo ("debug: get_sys_info()\n");
    echo ("done!\n");
    $result = ["sys_info" => print_r($this->ash->sysInfo->sysInfo, true)];
    return $result;
};
