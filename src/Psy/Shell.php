<?php

/*
 * This file is part of PsySH
 *
 * (c) 2012 Justin Hileman
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Psy;

use Psy\Configuration;
use Psy\Exception\BreakException;
use Psy\Exception\ErrorException;
use Psy\Exception\Exception as PsyException;
use Psy\Exception\RuntimeException;
use Psy\Formatter\ArrayFormatter;
use Psy\Formatter\ObjectFormatter;
use Psy\Output\ShellOutput;
use Psy\ShellAware;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

class Shell extends Application
{
    const VERSION = 'v0.0.1-dev';

    const PROMPT      = '>>> ';
    const BUFF_PROMPT = '... ';
    const REPLAY      = '--> ';
    const RETVAL      = '=> ';

    private $config;
    private $cleaner;
    private $output;
    private $inputBuffer;
    private $code;
    private $codeBuffer;
    private $scopeVariables;
    private $exceptions;

    /**
     * Create a new Psy shell.
     *
     * @param Configuration $config (default: null)
     */
    public function __construct(Configuration $config = null)
    {
        $this->config         = $config ?: new Configuration;
        $this->cleaner        = $this->config->getCodeCleaner();
        $this->loop           = $this->config->getLoop();
        $this->scopeVariables = array();

        parent::__construct('PsySH', self::VERSION);

        $this->config->setShell($this);
    }

    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message.'),
        ));
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return array An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        $commands = array(
            new Command\HelpCommand,
            new Command\ListCommand,
            new Command\DocCommand,
            new Command\ShowCommand,
            new Command\WtfCommand,
            new Command\TraceCommand,
            new Command\BufferCommand,
            new Command\ExitCommand,
            // new Command\PsyVersionCommand,
        );

        if (function_exists('readline')) {
            $commands[] = new Command\HistoryCommand;
        }

        return $commands;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \Exception When doRun returns Exception
     *
     * @api
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if ($output === null) {
            $output = $this->config->getOutput();
        }

        return parent::run($input, $output);
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $this->exceptions = array();
        $this->resetCodeBuffer();

        $this->setAutoExit(false);
        $this->setCatchExceptions(true);

        if ($this->config->useReadline()) {
            readline_read_history($this->config->getHistoryFile());
            readline_completion_function(array($this, 'autocomplete'));
        }

        $this->output->writeln($this->getHeader());

        $this->loop->run($this);
    }

    public function getInput()
    {
        do {
            // reset output verbosity (in case it was altered by a subcommand)
            $this->output->setVerbosity(ShellOutput::VERBOSITY_VERBOSE);

            $input = $this->readline();

            // handle Ctrl+D
            if ($input === false) {
                $this->output->writeln('');
                throw new BreakException('Ctrl+D');
            }

            // handle empty input
            if (!trim($input)) {
                continue;
            }

            if ($this->config->useReadline()) {
                readline_add_history($input);
                readline_write_history($this->config->getHistoryFile());
            }

            if ($this->hasCommand($input)) {
                $this->runCommand($input);
                continue;
            }

            $this->addCode($input);
        } while (!$this->hasValidCode());
    }

    public function beforeLoop()
    {
        $this->loop->beforeLoop();
    }

    public function setScopeVariables(array $vars)
    {
        unset($vars['__psysh__']);
        $this->scopeVariables = $vars;
    }

    public function getScopeVariables()
    {
        return $this->scopeVariables;
    }

    public function getScopeVariableNames()
    {
        return array_keys($this->getScopeVariables());
    }

    public function getScopeVariable($name)
    {
        if (!array_key_exists($name, $this->scopeVariables)) {
            throw new \InvalidArgumentException('Unknown variable: $'.$name);
        }

        return $this->scopeVariables[$name];
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function getLastException()
    {
        return end($this->exceptions);
    }

    protected function hasCode()
    {
        return !empty($this->codeBuffer);
    }

    protected function hasValidCode()
    {
        return $this->code !== false;
    }

    public function addCode($code)
    {
        $this->codeBuffer[] = $code;
        $this->code         = $this->cleaner->clean($this->codeBuffer);
    }

    public function getCodeBuffer()
    {
        return $this->codeBuffer;
    }

    protected function runCommand($input)
    {
        $command = $this->getCommand($input);
        if ($command instanceof ShellAware) {
            $command->setShell($this);
        }


        $input = new StringInput(str_replace('\\', '\\\\', rtrim($input, " \t\n\r\0\x0B;")));

        if ($input->hasParameterOption(array('--help', '-h'))) {
            $helpCommand = $this->get('help');
            $helpCommand->setCommand($command);

            return $helpCommand->run($input, $this->output);
        }

        $command->run($input, $this->output);
    }

    public function resetCodeBuffer()
    {
        $this->codeBuffer = array();
        $this->code       = false;
    }

    public function addInput($input)
    {
        foreach ((array) $input as $line) {
            $this->inputBuffer[] = $line;
        }
    }

    public function flushCode()
    {
        if ($this->hasValidCode()) {
            $code = $this->code;
            $this->resetCodeBuffer();

            return $code;
        }
    }

    public function setNamespace($namespace)
    {
        $this->cleaner->setNamespace($namespace);
    }

    public function getNamespace()
    {
        if ($namespace = $this->cleaner->getNamespace()) {
            return implode('\\', $namespace);
        }
    }

    public function writeStdout($out)
    {
        if (!empty($out)) {
            $this->output->writeln($out, ShellOutput::OUTPUT_RAW);
        }
    }

    public function writeReturnValue($ret)
    {
        $returnString = $this->formatValue($ret);
        if (strpos($returnString, '</return>') === false) {
            $this->output->writeln(sprintf("%s<return>%s</return>", self::RETVAL, $returnString));
        } else {
            $this->output->writeln(sprintf("%s%s", self::RETVAL, $returnString), ShellOutput::OUTPUT_RAW);
        }
    }

    public function writeException(\Exception $e)
    {
        $this->renderException($e, $this->output);
    }

    public function renderException($e, $output)
    {
        $this->exceptions[] = $e;

        $message = $e->getMessage();
        if (!$e instanceof PsyException) {
            $message = sprintf('%s with message \'%s\'', get_class($e), $message);
        }

        $severity = 'error';
        if ($e instanceof \ErrorException) {
            switch ($e->getSeverity()) {
                case E_WARNING:
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_USER_WARNING:
                case E_STRICT:
                    $severity = 'warning';
                    break;
            }
        }

        $this->output->writeln(sprintf('<%s>%s</%s>', $severity, $message, $severity));

        $this->resetCodeBuffer();
    }

    protected function formatValue($val)
    {
        // uppercase null is ugly.
        if ($val === null) {
            return 'null';
        } elseif (is_object($val)) {
            return ObjectFormatter::format($val);
        } elseif (is_array($val)) {
            return ArrayFormatter::format($val);
        } else {
            return json_encode($val);
        }
    }

    protected function getCommand($command)
    {
        $matches = array();
        if (preg_match('/^\s*([^\s]+)(?:\s|$)/', $command, $matches)) {
            return $this->get($matches[1]);
        }
    }

    protected function hasCommand($command)
    {
        $matches = array();
        if (preg_match('/^\s*([^\s]+)(?:\s|$)/', $command, $matches)) {
            return $this->has($matches[1]);
        }

        return false;
    }

    protected function getPrompt()
    {
        return $this->hasCode() ? self::BUFF_PROMPT : self::PROMPT;
    }

    protected function readline()
    {
        if (!empty($this->inputBuffer)) {
            $line = array_shift($this->inputBuffer);
            $this->output->writeln(sprintf('<aside>%s %s</aside>', self::REPLAY, $line));

            return $line;
        }

        if ($this->config->useReadline()) {
            return readline($this->getPrompt());
        } else {
            $this->output->write($this->getPrompt());

            return rtrim(fgets(STDIN, 1024));
        }
    }

    protected function getHeader()
    {
        return sprintf(
            "<aside>PsySH %s (PHP %s — %s) by Justin Hileman</aside>",
            self::VERSION,
            phpversion(),
            php_sapi_name()
        );
    }

    public function getVersion()
    {
        return sprintf("PsySH %s (PHP %s — %s)", self::VERSION, phpversion(), php_sapi_name());
    }

    protected function autocomplete($text)
    {
        $info = readline_info();
        // $line = substr($info['line_buffer'], 0, $info['end']);

        // Check whether there's a command for this
        // $words = explod(' ', $line);
        // $firstWord = reset($words);

        // check whether this is a variable...
        $firstChar = substr($info['line_buffer'], max(0, $info['end'] - strlen($text) - 1), 1);
        if ($firstChar == '$') {
            return $this->getScopeVariableNames();
        }
    }
}