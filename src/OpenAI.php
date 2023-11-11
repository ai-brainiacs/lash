<?php

namespace Rpurinton\Ash;

class OpenAI
{
    private $client = null;
    private $models = [];
    private $model = null;
    private $maxTokens = null;
    private $basePrompt = null;
    private $baseTokens = 0;
    private $running_process = null;
    private $encoder = null;
    private $util = null;
    public $history = null;

    public function __construct(private $ash)
    {
        $this->util = new Util();
        $this->history = new History($this->util);
        $this->client = \OpenAI::client($this->ash->config->config['openaiApiKey']);
        $models = $this->client->models()->list()->data;
        foreach ($models as $model) if (substr($model->id, 0, 3) == 'gpt') $this->models[] = $model->id;
        $this->selectModel();
        $this->selectMaxTokens();
        $this->basePrompt = file_get_contents(__DIR__ . "/base_prompt.txt");
        $this->baseTokens = $this->util->tokenCount($this->basePrompt);
        $this->welcomeMessage();
    }

    public function __destruct()
    {
        if ($this->running_process) {
            proc_terminate($this->running_process);
            $this->running_process = null;
        }
    }

    public function selectModel($force = false)
    {
        // Check if openai_model is set in the config
        if (!$force && isset($this->ash->config->config['openaiModel'])) {
            $model_id = $this->ash->config->config['openaiModel'];
            // Check if the model is in the list of models
            if (in_array($model_id, $this->models)) {
                $this->model = $model_id;
                return;
            }
        }

        // Prompt the user to select a model
        while (true) {
            $model_count = count($this->models);
            $prompt = "(ash) Please select an OpenAI GPT model to use:\n";
            for ($i = 0; $i < $model_count; $i++) {
                $prompt .= "(ash) [$i] {$this->models[$i]}\n";
            }
            $prompt .= "(ash) Enter the number of the model to use (default: 0 ({$this->models[0]})): ";
            $model_index = readline($prompt);
            if ($model_index == "") $model_index = 0;

            // Check if the selected model is valid
            if (isset($this->models[$model_index])) {
                $this->model = $this->models[$model_index];
                $this->ash->config->setOpenAIModel($this->model);
                return;
            }

            echo "(ash) Invalid model selected. Please try again.\n";
        }
    }

    public function selectMaxTokens($force = false)
    {

        if (!$force && isset($this->ash->config->config['openaiTokens'])) {
            $this->maxTokens = $this->ash->config->config['openaiTokens'];
            return;
        }

        while (true) {
            $prompt = "(ash) Please select the maximum tokens you want use for any single request (default: 4096, range [2048-131072]): ";
            $max_tokens = readline($prompt);
            if ($max_tokens == "") $max_tokens = 4096;

            if (is_numeric($max_tokens) && $max_tokens >= 2048 && $max_tokens <= 131072) {
                $this->maxTokens = $max_tokens;
                $this->ash->config->setOpenAITokens($this->maxTokens);
                return;
            }

            echo "(ash) Invalid max tokens value. Please try again.\n";
        }
    }

    public function welcomeMessage()
    {
        $messages[] = ["role" => "system", "content" => $this->basePrompt];
        $response_space = round($this->maxTokens * 0.1, 0);
        $history_space = $this->maxTokens - $this->baseTokens - $response_space;
        $messages = array_merge($messages, $this->history->getHistory($history_space));
        $messages[] = ["role" => "system", "content" => "Your full name is " . $this->ash->sysInfo->sysInfo['hostFQDN'] . ", but people can call you " . $this->ash->sysInfo->sysInfo['hostName'] . " for short."];
        $messages[] = ["role" => "system", "content" => "Here is the current situation: " . print_r($this->ash->sysInfo->sysInfo, true)];
        if ($this->ash->config->config['colorSupport']) $messages[] = ["role" => "system", "content" => "Terminal  \e[31mcolor \e[32msupport\e[0m enabled! use it to highlight keywords and such.  for example use purple for directory or folder names, green for commands, and red for errors, blue for symlinks, gray for data files etc. blue for URLs, etc. You can also use alternating colors when displaying tables of information to make them easier to read.  \e[31mred \e[32mgreen \e[33myellow \e[34mblue \e[35mpurple \e[36mcyan \e[37mgray \e[0m"];
        if ($this->ash->config->config['emojiSupport']) $messages[] = ["role" => "system", "content" => "Emoji support enabled!  Use it to express yourself!  🤣🤣🤣"];
        $login_message = "User just started a new ash session from : " . $this->ash->sysInfo->sysInfo["who-u"];
        $messages[] = ["role" => "system", "content" => $login_message . "\nPlease run any functions you want before we get started then write a welcome message from you (" . $this->ash->sysInfo->sysInfo['hostName'] . ") to " . $this->ash->sysInfo->sysInfo['userId'] . "."];
        $login_message = ["role" => "system", "content" => $login_message];
        $this->history->saveMessage($login_message);
        $messages[] = ["role" => "system", "content" => "Be sure to word-wrap your response to 80 characters or less by including line breaks in all messages."];
        $messages[] = ["role" => "system", "content" => "Markdown support disabled, don't include and ``` or markdown formatting. This is just a text-CLI."];
        $prompt = [
            "model" => $this->model,
            "messages" => $messages,
            "max_tokens" => $this->maxTokens,
            "temperature" => 0.1,
            "top_p" => 0.1,
            "frequency_penalty" => 0.0,
            "presence_penalty" => 0.0,
            "functions" => $this->getFunctions(),
        ];
        $full_response = "";
        $function_call = null;
        if ($this->ash->debug) echo ("(ash) Sending prompt to OpenAI: " . print_r($prompt, true) . "\n");
        echo ("(ash) ");
        $stream = $this->client->chat()->createStreamed($prompt);
        foreach ($stream as $response) {
            $reply = $response->choices[0]->toArray();
            $finish_reason = $reply["finish_reason"];
            if (isset($reply["delta"]["function_call"]["name"])) {
                $function_call = $reply["delta"]["function_call"]["name"];
                echo ("✅ Running $function_call...\n");
            }
            if ($function_call) {
                if (isset($reply["delta"]["function_call"]["arguments"])) $full_response .= $reply["delta"]["function_call"]["arguments"];
            } else if (isset($reply["delta"]["content"])) {
                $delta_content = $reply["delta"]["content"];
                $full_response .= $delta_content;
                echo ($delta_content);
            }
        }
        if ($function_call) {
            $arguments = json_decode($full_response, true);
        } else {
            $assistant_message = ["role" => "assistant", "content" => $full_response];
            $this->history->saveMessage($assistant_message);
        }
        echo ("\n\n");
        if ($this->ash->debug) {
            if ($function_call) echo ("(ash) ✅ Response complete.  Function call: " . print_r($arguments, true) . "\n");
            else echo ("(ash) Response complete.\n");
        }
    }

    public function userMessage($input)
    {
        $user_message = ["role" => "user", "content" => $input];
        $this->history->saveMessage($user_message);
        $messages[] = ["role" => "system", "content" => $this->basePrompt];
        $dynamic_prompt = "Your full name is " . $this->ash->sysInfo->sysInfo['hostFQDN'] . ", but people can call you " . $this->ash->sysInfo->sysInfo['hostName'] . " for short. Here is the current situation: " . print_r($this->ash->sysInfo->sysInfo, true);
        if ($this->ash->config->config['emojiSupport']) $dynamic_prompt .= "Emoji support enabled!  Use it to express yourself!  🤣🤣🤣\n";
        $dynamic_prompt .= "Be sure to word-wrap your response to 80 characters or less by including line breaks in all messages. Markdown support is disabled, don't include ``` or any other markdown formatting. This is just a text-CLI.\n";
        if ($this->ash->config->config['colorSupport']) $dynamic_prompt .= "Terminal  \e[31mcolor \e[32msupport\e[0m enabled! use it to highlight keywords and such.  for example use purple for directory or folder names, green for commands, and red for errors, blue for symlinks, gray for data files etc. blue for URLs, etc. You can also use alternating colors when displaying tables of information to make them easier to read.  \e[31mred \e[32mgreen \e[33myellow \e[34mblue \e[35mpurple \e[36mcyan \e[37mgray \e[0m.  Don't send the escape codes, send the actual unescaped color control symbols.\n";
        $messages[] = ["role" => "system", "content" => $dynamic_prompt];
        $dynamic_tokens = $this->util->tokenCount($dynamic_prompt);
        $response_space = round($this->maxTokens * 0.1, 0);
        $history_space = $this->maxTokens - $this->baseTokens - $dynamic_tokens - $response_space;
        $messages = array_merge($messages, $this->history->getHistory($history_space));
        $prompt = [
            "model" => $this->model,
            "messages" => $messages,
            "max_tokens" => $this->maxTokens,
            "temperature" => 0.1,
            "top_p" => 0.1,
            "frequency_penalty" => 0.0,
            "presence_penalty" => 0.0,
            "functions" => $this->getFunctions(),
        ];
        $full_response = "";
        $function_call = null;
        if ($this->ash->debug) echo ("(ash) Sending prompt to OpenAI: " . print_r($prompt, true) . "\n");
        echo ("(ash) ");
        $stream = $this->client->chat()->createStreamed($prompt);
        foreach ($stream as $response) {
            $reply = $response->choices[0]->toArray();
            $finish_reason = $reply["finish_reason"];
            if (isset($reply["delta"]["function_call"]["name"])) {
                $function_call = $reply["delta"]["function_call"]["name"];
                if ($this->ash->debug) echo ("(ash) ✅ Running $function_call...\n");
            }
            if ($function_call) {
                if (isset($reply["delta"]["function_call"]["arguments"])) $full_response .= $reply["delta"]["function_call"]["arguments"];
            } else if (isset($reply["delta"]["content"])) {
                $delta_content = $reply["delta"]["content"];
                $full_response .= $delta_content;
                echo ($delta_content);
            }
        }
        if ($function_call) {
            $arguments = json_decode($full_response, true);
        } else {
            $assistant_message = ["role" => "assistant", "content" => $full_response];
            $this->history->saveMessage($assistant_message);
        }
        echo ("\n\n");
        if ($this->ash->debug) {
            if ($function_call) echo ("(ash) ✅ Response complete.  Function call: " . print_r($arguments, true) . "\n");
            else echo ("(ash) Response complete.\n");
        }
    }

    private function getFunctions()
    {
        exec('ls ' . __DIR__ . '/functions.d/*.json', $functions);
        $result = [];
        foreach ($functions as $function) $result[] = json_decode(file_get_contents($function), true);
        return $result;
    }

    public function procExec(array $input): array
    {
        if ($this->ash->debug) echo ("(ash) proc_exec(" . print_r($input, true) . ")\n");
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];
        $pipes = [];
        try {
            $this->running_process = proc_open($input['command'], $descriptorspec, $pipes, $input['cwd'] ?? $this->ash->sysInfo->sysInfo['working_dir'], $input['env'] ?? []);
        } catch (\Exception $e) {
            return [
                "stdout" => "",
                "stderr" => "Error (ash): proc_open() failed: " . $e->getMessage(),
                "exit_code" => -1,
            ];
        }
        if (is_resource($this->running_process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit_code = proc_close($this->running_process);
            $this->running_process = null;
            $result = [
                "stdout" => $stdout,
                "stderr" => $stderr,
                "exit_code" => $exit_code,
            ];
            if ($this->ash->debug) echo ("(ash) proc_exec() result: " . print_r($result, true) . "\n");
            return $result;
        }
        return [
            "stdout" => "",
            "stderr" => "Error (ash): proc_open() failed.",
            "exit_code" => -1,
        ];
    }
}
