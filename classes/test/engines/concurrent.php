<?php

namespace mageekguy\atoum\test\engines;

use
	mageekguy\atoum,
	mageekguy\atoum\test,
	mageekguy\atoum\exceptions
;

class concurrent extends test\engine
{
	protected $adapter = null;
	protected $scoreFactory = null;
	protected $test = null;
	protected $method = '';
	protected $stdOut = '';
	protected $stdErr = '';

	private $php = null;
	private $pipes = array();

	public function __construct()
	{
		$this
			->setAdapter()
			->setScoreFactory()
		;
	}

	public function setAdapter(atoum\adapter $adapter = null)
	{
		$this->adapter = $adapter ?: new atoum\adapter();

		return $this;
	}

	public function getAdapter()
	{
		return $this->adapter;
	}

	public function setScoreFactory(\closure $factory = null)
	{
		$this->scoreFactory = $factory ?: function() { return new atoum\score(); };

		return $this;
	}

	public function getScoreFactory()
	{
		return $this->scoreFactory;
	}

	public function isAsynchronous()
	{
		return true;
	}

	public function run(atoum\test $test)
	{
		$currentTestMethod = $test->getCurrentMethod();

		if ($currentTestMethod !== null)
		{
			$this->test = $test;
			$this->method = $currentTestMethod;
			$this->stdOut = '';
			$this->stdErr = '';

			$phpPath = $this->test->getPhpPath();

			$this->php = @call_user_func_array(array($this->adapter, 'proc_open'), array(escapeshellarg($phpPath), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), & $this->pipes));

			if ($this->php === false)
			{
				throw new exceptions\runtime('Unable to use \'' . $phpPath . '\'');
			}

			$phpCode =
				'<?php ' .
				'ob_start();' .
				'require \'' . atoum\directory . '/classes/autoloader.php\';'
			;

			$bootstrapFile = $this->test->getBootstrapFile();

			if ($bootstrapFile !== null)
			{
				$phpCode .=
					'$includer = new mageekguy\atoum\includer();' .
					'try { $includer->includePath(\'' . $bootstrapFile . '\'); }' .
					'catch (mageekguy\atoum\includer\exception $exception)' .
					'{ die(\'Unable to include bootstrap file \\\'' . $bootstrapFile . '\\\'\'); }'
				;
			}

			$phpCode .=
				'require \'' . $this->test->getPath() . '\';' .
				'$test = new ' . $this->test->getClass() . '();' .
				'$test->setLocale(new ' . get_class($this->test->getLocale()) . '(' . $this->test->getLocale()->get() . '));' .
				'$test->setPhpPath(\'' . $phpPath . '\');'
			;

			if ($this->test->debugModeIsEnabled() === true)
			{
				$phpCode .= '$test->enableDebugMode();';
			}

			if ($this->test->codeCoverageIsEnabled() === false)
			{
				$phpCode .= '$test->disableCodeCoverage();';
			}
			else
			{
				$phpCode .= '$coverage = $test->getCoverage();';

				foreach ($this->test->getCoverage()->getExcludedClasses() as $excludedClass)
				{
					$phpCode .= '$coverage->excludeClass(\'' . $excludedClass . '\');';
				}

				foreach ($this->test->getCoverage()->getExcludedNamespaces() as $excludedNamespace)
				{
					$phpCode .= '$coverage->excludeNamespace(\'' . $excludedNamespace . '\');';
				}

				foreach ($this->test->getCoverage()->getExcludedDirectories() as $excludedDirectory)
				{
					$phpCode .= '$coverage->excludeDirectory(\'' . $excludedDirectory . '\');';
				}
			}

			$phpCode .=
				'ob_end_clean();' .
				'mageekguy\atoum\scripts\runner::disableAutorun();' .
				'echo serialize($test->runTestMethod(\'' . $this->method . '\')->getScore());'
			;

			$this->adapter->fwrite($this->pipes[0], $phpCode, strlen($phpCode));
			$this->adapter->fclose($this->pipes[0]);
			unset($this->pipes[0]);

			$this->adapter->stream_set_blocking($this->pipes[1], 0);
			$this->adapter->stream_set_blocking($this->pipes[2], 0);
		}

		return $this;
	}

	public function getScore()
	{
		$score = null;

		if ($this->php !== null)
		{
			$phpStatus = $this->adapter->proc_get_status($this->php);

			if ($phpStatus['running'] == true)
			{
				$this->stdOut .= $this->adapter->stream_get_contents($this->pipes[1]);
				$this->stdErr .= $this->adapter->stream_get_contents($this->pipes[2]);
			}
			else
			{
				$this->stdOut .= $this->adapter->stream_get_contents($this->pipes[1]);
				$this->adapter->fclose($this->pipes[1]);

				$this->stdErr .= $this->adapter->stream_get_contents($this->pipes[2]);
				$this->adapter->fclose($this->pipes[2]);

				$this->pipes = array();

				$this->adapter->proc_close($this->php);
				$this->php = null;

				$score = @unserialize($this->stdOut);

				if ($score instanceof atoum\score === false)
				{
					$score = call_user_func($this->scoreFactory);
					$score->addUncompletedMethod($this->test->getClass(), $this->method, $phpStatus['exitcode'], $this->stdOut);
				}

				if ($this->stdErr !== '')
				{
					if (preg_match_all('/([^:]+): (.+) in (.+) on line ([0-9]+)/', trim($this->stdErr), $errors, PREG_SET_ORDER) === 0)
					{
						$score->addError($this->test->getPath(), $this->test->getClass(), $this->method, null, 'UNKNOWN', $this->stdErr);
					}
					else foreach ($errors as $error)
					{
						$score->addError($this->test->getPath(), $this->test->getClass(), $this->method, null, $error[1], $error[2], $error[3], $error[4]);
					}
				}

				$this->stdOut = '';
				$this->stdErr = '';
			}
		}

		return $score;
	}
}
