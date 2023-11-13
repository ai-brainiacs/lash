<?php

namespace Rpurinton\Ash;

class ParseArgs
{
    public function parseArgs($ash)
    {
        global $argv;
        foreach ($argv as $key => $arg) {
            switch ($arg) {
                case "/v":
                case "/version":
                    die("ash version 0.0.1-alpha rpurinton 2023\n");
                case "/h":
                case "/help":
                    die(shell_exec("cat " . __DIR__ . "/../README.md") . "\n");
                case "/license":
                    die(shell_exec("cat " . __DIR__ . "/../LICENSE") . "\n");
                case "/d":
                case "/debug":
                    $ash->debug = true;
                    echo "Debug mode enabled.\n";
                    break;
                case "/m":
                case "/message":
                    $ash->openai->userMessage($argv[$key + 1], false);
                    die();
                case "/reconfig":
                    $ash->config->initialConfig();
                    break;
                case "/reinstall":
                    (new Composer())->install_dependencies($ash->debug);
                    break;
                case "/uninstall":
                    shell_exec("rm -rfv ~/.ash_history.jsonl");
                    shell_exec("rm -rfv " . __DIR__ . "/conf.d");
                    shell_exec("rm -rfv " . __DIR__ . "/vendor");
                    shell_exec("rm -rfv " . __DIR__ . "/composer.lock");
                    die("Uninstalled.\n");
            }
        }
    }
}
