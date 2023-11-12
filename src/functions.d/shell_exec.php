<?php
$this->functionHandlers['shell_exec'] = function ($args) {
    if ($this->ash->debug) echo ("debug: shell_exec(" . print_r($args, true) . ")\n");
    $command = $args['command'];
    $output = shell_exec($command);
    $result = [
        "stdout" => $output,
        "stderr" => "",
        "exitCode" => 0,
    ];
    if ($this->ash->debug) echo ("debug: shell_exec() result: " . print_r($result, true) . "\n");
    return $result;
};