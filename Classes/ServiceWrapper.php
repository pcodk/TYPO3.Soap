<?php
declare(ENCODING = 'utf-8');
namespace F3\Soap;

/*                                                                        *
 * This script belongs to the FLOW3 package "Soap".                       *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * A wrapper for services to map arguments and handle exceptions in a
 * SoapServer friendly way.
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @scope prototype
 */
class ServiceWrapper {

	/**
	 * The wrapped service object
	 *
	 * @var object
	 */
	protected $service;

	/**
	 * @inject
	 * @var \F3\FLOW3\Reflection\ReflectionService
	 */
	protected $reflectionService;

	/**
	 * @inject
	 * @var \F3\FLOW3\Property\PropertyMapper
	 */
	protected $propertyMapper;

	/**
	 * @inject
	 * @var \F3\FLOW3\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @inject
	 * @var \F3\FLOW3\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * @var array
	 */
	protected $settings = array();

	/**
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param object $service The service object to wrap
	 */
	public function __construct($service) {
		$this->service = $service;
	}

	/**
	 * The SoapServer will call methods with parameters as stdClass instances.
	 * This magic call method will convert the parameters to the object types
	 * specified in the SOAP service class.
	 *
	 * @param string $methodName Method name called
	 * @param array $arguments Arguments of the call
	 * @return mixed
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function __call($methodName, $arguments) {
		$className = ($this->service instanceof \F3\FLOW3\AOP\ProxyInterface) ? $this->service->FLOW3_AOP_Proxy_getProxyTargetClassName() : get_class($this->service);
		$methodParameters = $this->reflectionService->getMethodParameters($className, $methodName);
		foreach ($methodParameters as $parameterName => $parameterOptions) {
			if (isset($parameterOptions['class'])) {
				$className = $parameterOptions['class'];
				if ($this->reflectionService->isClassReflected($className)) {
					$arguments[$parameterOptions['position']] = $this->convertStdClassToObject($arguments[$parameterOptions['position']], $className, $parameterName);
				}
			} elseif (isset($parameterOptions['type'])) {
				$type = $parameterOptions['type'];
				if (preg_match('/^array<(.+)>$/', $type, $matches)) {
					$className = $matches[1];
					$typeName = strpos($className, '\\') !== FALSE ? substr($className, strrpos($className, '\\') + 1) : $className;
					$arrayValues = $arguments[$parameterOptions['position']]->$typeName;
					if ($this->reflectionService->isClassReflected($className)) {
						$result = array();
						foreach ($arrayValues as $arrayValue) {
							$result[] = $this->convertStdClassToObject($arrayValue, $className, $parameterName);
						}
					} else {
						$arguments[$parameterOptions['position']] = $arrayValues;
					}
				}
			}
		}
		try {
			return call_user_func_array(array($this->service, $methodName), $arguments);
		} catch(\Exception $exception) {
			$this->handleException($exception, $className, $methodName);
		}
	}

	/**
	 * Convert the thrown exception to a corresponding SOAP fault,
	 * respecting expected and unexpected Exceptions by looking at the
	 * throws annotation of the method declaration.
	 *
	 * @param \Exception $exception The exception that was thrown in the service call
	 * @param string $className The class name of the service
	 * @param string $methodName The method name of the service that was called
	 * @return void
	 * @throws \SoapFault The exception converted to a SoapFault
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function handleException($exception, $className, $methodName) {
		$exceptionClassName = get_class($exception);

		$expectedException = $this->methodThrowsException($className, $methodName, $exceptionClassName);
		if ($expectedException) {
			$exceptionName = implode('_', array_slice(explode('\\', $exceptionClassName), 4));
			throw new \SoapFault('Client', $exception->getMessage(), NULL, $exceptionName);
		} else {
			if ($this->settings['exposeExceptionInformation'] === TRUE) {
				$message = $exceptionClassName . ' (' . $exception->getCode() . '): ' . $exception->getMessage();
				$stackTrace = $exception->getTraceAsString();
				$details = $stackTrace;
				$identifier = NULL;
			} else {
				$identifier = \F3\FLOW3\Utility\Algorithms::generateUUID();
				$message = 'Internal server error. The error was logged as ' . $identifier;
				$details = $identifier;
			}
			$this->logException($exception, $identifier);

			throw new \SoapFault('Server', $message, NULL, $details);
		}
	}

	/**
	 * Check if the given method throws the specified exception in a
	 * "throws" annotation.
	 *
	 * @param string $className The class name for the method
	 * @param string $methodName The method name
	 * @param string $exceptionClassName The exception class name
	 * @return boolean
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function methodThrowsException($className, $methodName, $exceptionClassName) {
		$methodTagsValues = $this->reflectionService->getMethodTagsValues($className, $methodName);
		if (isset($methodTagsValues['throws'])) {
			if (is_array($methodTagsValues['throws'])) {
				foreach ($methodTagsValues['throws'] as $throwsDefinition) {
					list($throwsType,) = \F3\FLOW3\Utility\Arrays::trimExplode(' ', $throwsDefinition, TRUE);
					if (ltrim($exceptionClassName, '\\') == ltrim($throwsType, '\\')) {
						return TRUE;
					}
				}
			}
		}
		return FALSE;
	}

	/**
	 * Logs the given exception with an identifier to find the specific log
	 * for debugging purposes.
	 *
	 * @param \Exception $exception The exception object
	 * @param string $identifier
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function logException(\Exception $exception, $identifier = NULL) {
		if (is_object($this->systemLogger)) {
			$exceptionCodeNumber = ($exception->getCode() > 0) ? ' #' . $exception->getCode() : '';
			$backTrace = $exception->getTrace();
			$className = isset($backTrace[0]['class']) ? $backTrace[0]['class'] : '?';
			$methodName = isset($backTrace[0]['function']) ? $backTrace[0]['function'] : '?';
			$line = isset($backTrace[0]['line']) ? ' in line ' . $backTrace[0]['line'] . ' of ' . $backTrace[0]['file'] : '';
			$message = 'Uncaught exception' . $exceptionCodeNumber . '. ' . $exception->getMessage() . $line . '.';

			$explodedClassName = explode('\\', $className);
			$packageKey = (isset($explodedClassName[1])) ? $explodedClassName[1] : NULL;

			$additionalData = array();
			if ($identifier !== NULL) {
				$additionalData['identifier'] = $identifier;
			}

			if ($this->settings['logDetailedExceptions'] === TRUE) {
				$additionalData['stacktrace'] = $exception->getTrace();
			}

			$this->systemLogger->log($message, LOG_CRIT, $additionalData, $packageKey, $className, $methodName);
		}
	}

	/**
	 * Convert the given argument from stdClass to a FLOW3 object with the
	 * specified class name.
	 *
	 * @param \stdClass $argument The argument
	 * @param string $className The class name of the target object
	 * @param string $parameterName The parameter name of the argument
	 * @return object The converted object
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function convertStdClassToObject($argument, $className, $parameterName) {
		$target = $this->objectManager->create($className);
		if ($this->propertyMapper->map($this->reflectionService->getClassPropertyNames($className), \F3\FLOW3\Utility\Arrays::convertObjectToArray($argument), $target)) {
			return $target;
		} else {
			throw new \F3\Soap\MappingException('Could not map argument ' . $parameterName . ' to type ' . $className, $this->propertyMapper->getMappingResults());
		}
	}
}
?>