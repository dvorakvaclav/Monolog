<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\Monolog\DI;

use Nette;
use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Statement;
use Nette\PhpGenerator as Code;
use Tracy\Debugger;



/**
 * Integrates the Monolog seamlessly into your Nette Framework application.
 *
 * @author Martin Bažík <martin@bazo.sk>
 * @author Filip Procházka <filip@prochazka.su>
 */
class MonologExtension extends CompilerExtension
{

	/** @internal */
	const TRACY_EXCEPTION_HANDLER_SERVICE_ID = 'handler.tracyException';

	const TAG_HANDLER = 'monolog.handler';
	const TAG_PROCESSOR = 'monolog.processor';
	const TAG_PRIORITY = 'monolog.priority';

	private $defaults = [
		'handlers' => [],
		'processors' => [],
		'name' => 'app',
		'hookToTracy' => TRUE,
		'tracyBaseUrl' => NULL,
		'usePriorityProcessor' => TRUE,
		// 'registerFallback' => TRUE,
	];



	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		if (!isset($builder->parameters[$this->name]) || (is_array($builder->parameters[$this->name]) && !isset($builder->parameters[$this->name]['name']))) {
			$builder->parameters[$this->name]['name'] = $config['name'];
		}

		$builder->addDefinition($this->prefix('logger'))
			->setClass('Kdyby\Monolog\Logger', [$config['name']]);

		if (!isset($builder->parameters['logDir'])) {
			if (Debugger::$logDirectory) {
				$builder->parameters['logDir'] = Debugger::$logDirectory;

			} else {
				$builder->parameters['logDir'] = $builder->parameters['appDir'] . '/../log';
			}
		}

		if (!@mkdir($builder->parameters['logDir'], 0777, true) && !is_dir($builder->parameters['logDir'])) {
			throw new \RuntimeException(sprintf('Log dir %s cannot be created', $builder->parameters['logDir']));
		}

		$this->loadHandlers($config);
		$this->loadProcessors($config);

		// Tracy adapter
		$builder->addDefinition($this->prefix('adapter'))
			->setClass('Kdyby\Monolog\Tracy\MonologAdapter', [
				'monolog' => $this->prefix('@logger'),
				'logDirectory' => $builder->parameters['logDir'],
				'email' => Debugger::$email,
			])
			->addTag('logger');

		if ($config['hookToTracy'] === TRUE && $builder->hasDefinition('tracy.logger')) {
			// TracyExtension initializes the logger from DIC, if definition is changed
			$builder->removeDefinition($existing = 'tracy.logger');
			$builder->addAlias($existing, $this->prefix('adapter'));
		}
	}



	protected function loadHandlers(array $config)
	{
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix(self::TRACY_EXCEPTION_HANDLER_SERVICE_ID))
			->setClass('Kdyby\Monolog\Handler\TracyExceptionHandler', [
				'logger' => $this->prefix('@adapter'),
			])
			->addTag(self::TAG_HANDLER)
			->addTag(self::TAG_PRIORITY, 100);

		foreach ($config['handlers'] as $handlerName => $implementation) {
			Compiler::loadDefinitions($builder, [
				$serviceName = $this->prefix('handler.' . $handlerName) => $implementation,
			]);

			$builder->getDefinition($serviceName)
				->addTag(self::TAG_HANDLER)
				->addTag(self::TAG_PRIORITY, is_numeric($handlerName) ? $handlerName : 0);
		}
	}



	protected function loadProcessors(array $config)
	{
		$builder = $this->getContainerBuilder();

		if ($config['usePriorityProcessor'] === TRUE) {
			// change channel name to priority if available
			$builder->addDefinition($this->prefix('processor.priorityProcessor'))
				->setClass('Kdyby\Monolog\Processor\PriorityProcessor')
				->addTag(self::TAG_PROCESSOR)
				->addTag(self::TAG_PRIORITY, 20);
		}

		if ($config['tracyBaseUrl'] !== NULL) {
			$builder->addDefinition($this->prefix('processor.tracyBaseUrl'))
				->setClass('Kdyby\Monolog\Processor\TracyUrlProcessor', [
					'baseUrl' => $config['tracyBaseUrl'],
				])
				->addTag(self::TAG_PROCESSOR)
				->addTag(self::TAG_PRIORITY, 10);
		}

		foreach ($config['processors'] as $processorName => $implementation) {
			Compiler::loadDefinitions($builder, [
				$serviceName = $this->prefix('processor.' . $processorName) => $implementation,
			]);

			$builder->getDefinition($serviceName)
				->addTag(self::TAG_PROCESSOR)
				->addTag(self::TAG_PRIORITY, is_numeric($processorName) ? $processorName : 0);
		}
	}



	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();
		$logger = $builder->getDefinition($this->prefix('logger'));

		foreach ($handlers = $this->findByTagSorted(self::TAG_HANDLER) as $serviceName => $meta) {
			$logger->addSetup('pushHandler', ['@' . $serviceName]);
		}

		foreach ($this->findByTagSorted(self::TAG_PROCESSOR) as $serviceName => $meta) {
			$logger->addSetup('pushProcessor', ['@' . $serviceName]);
		}

		unset($handlers[$this->prefix(self::TRACY_EXCEPTION_HANDLER_SERVICE_ID)]); // tracy exception handler doesn't count
		$config = $this->getConfig(['registerFallback' => empty($handlers)] + $this->getConfig($this->defaults));

		if ($config['registerFallback']) {
			$logger->addSetup('pushHandler', [
				new Statement('Kdyby\Monolog\Handler\FallbackNetteHandler', [
					'appName' => $config['name'],
					'logDir' => $builder->parameters['logDir']
				])
			]);
		}
	}



	protected function findByTagSorted($tag)
	{
		$builder = $this->getContainerBuilder();

		$services = $builder->findByTag($tag);
		uksort($services, function ($nameA, $nameB) use ($builder) {
			$pa = $builder->getDefinition($nameA)->getTag(self::TAG_PRIORITY) ?: 0;
			$pb = $builder->getDefinition($nameB)->getTag(self::TAG_PRIORITY) ?: 0;
			return $pa > $pb ? 1 : ($pa < $pb ? -1 : 0);
		});

		return $services;
	}



	public function afterCompile(Code\ClassType $class)
	{
		$builder = $this->getContainerBuilder();
		$initialize = $class->getMethod('initialize');

		if (empty(Debugger::$logDirectory)) {
			$initialize->addBody('\Tracy\Debugger::$logDirectory = ?;', [$builder->parameters['logDir']]);
		}
	}



	public static function register(Configurator $configurator)
	{
		$configurator->onCompile[] = function ($config, Compiler $compiler) {
			$compiler->addExtension('monolog', new MonologExtension());
		};
	}

}