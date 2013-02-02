<?php
/**
 * HelpController class file.
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\console\controllers;

use Yii;
use yii\base\Application;
use yii\console\BadUsageException;
use yii\base\InlineAction;
use yii\console\Controller;
use yii\util\StringHelper;

/**
 * This command provides help information about console commands.
 *
 * This command displays the available command list in
 * the application or the detailed instructions about using
 * a specific command.
 *
 * This command can be used as follows on command line:
 *
 * ~~~
 * yiic help [command name]
 * ~~~
 *
 * In the above, if the command name is not provided, all
 * available commands will be displayed.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class HelpController extends Controller
{
	/**
	 * Displays available commands or the detailed information
	 * about a particular command. For example,
	 *
	 * ~~~
	 * yiic help          # list available commands
	 * yiic help message  # display help info about "message"
	 * ~~~
	 *
	 * @param array $args additional anonymous command line arguments.
	 * You may provide a command name to display its detailed information.
	 * @return integer the exit status
	 * @throws BadUsageException if the command for help is unknown
	 */
	public function actionIndex($args = array())
	{
		if (empty($args)) {
			$this->getHelp();
		} else {
			$result = Yii::$application->createController($args[0]);
			if ($result === false) {
				throw new BadUsageException(Yii::t('yii', 'No help for unknown command "{command}".', array(
					'{command}' => $args[0],
				)));
			}

			list($controller, $actionID) = $result;

			if ($actionID === '') {
				$this->getControllerHelp($controller);
			} else {
				$this->getActionHelp($controller, $actionID);
			}
		}
	}

	/**
	 * Returns all available command names.
	 * @return array all available command names
	 */
	public function getCommands()
	{
		$commands = $this->getModuleCommands(Yii::$application);
		sort($commands);
		return array_unique($commands);
	}

	/**
	 * Returns all available actions of the specified controller.
	 * @param Controller $controller the controller instance
	 * @return array all available action IDs.
	 */
	public function getActions($controller)
	{
		$actions = array_keys($controller->actions());
		$class = new \ReflectionClass($controller);
		foreach ($class->getMethods() as $method) {
			$name = $method->getName();
			if ($method->isPublic() && !$method->isStatic() && strpos($name, 'action') === 0 && $name !== 'actions') {
				$actions[] = StringHelper::camel2id(substr($name, 6));
			}
		}
		sort($actions);
		return array_unique($actions);
	}

	/**
	 * Returns available commands of a specified module.
	 * @param \yii\base\Module $module the module instance
	 * @return array the available command names
	 */
	protected function getModuleCommands($module)
	{
		$prefix = $module instanceof Application ? '' : $module->getUniqueID() . '/';

		$commands = array();
		foreach (array_keys($module->controllerMap) as $id) {
			$commands[] = $prefix . $id;
		}

		foreach ($module->getModules() as $id => $child) {
			if (($child = $module->getModule($id)) === null) {
				continue;
			}
			foreach ($this->getModuleCommands($child) as $command) {
				$commands[] = $prefix . $id . '/' . $command;
			}
		}

		$files = scandir($module->getControllerPath());
		foreach ($files as $file) {
			if(strcmp(substr($file,-14),'Controller.php') === 0 && is_file($file)) {
				$commands[] = $prefix . lcfirst(substr(basename($file), 0, -14));
			}
		}

		return $commands;
	}

	/**
	 * Displays all available commands.
	 */
	protected function getHelp()
	{
		$commands = $this->getCommands();
		if ($commands !== array()) {
			echo "\nUsage: yiic <command-name> [...options...]\n\n";
			echo "The following commands are available:\n\n";
			foreach ($commands as $command) {
				echo " * $command\n";
			}
			echo "\nTo see the help of each command, enter:\n";
			echo "\n    yiic help <command-name>\n";
		} else {
			echo "\nNo commands are found.\n";
		}
	}

	/**
	 * Displays the overall information of the command.
	 * @param Controller $controller the controller instance
	 */
	protected function getControllerHelp($controller)
	{
		$class = new \ReflectionClass($controller);
		$comment = strtr(trim(preg_replace('/^\s*\**( |\t)?/m', '', trim($class->getDocComment(), '/'))), "\r", '');
		if (preg_match('/^\s*@\w+/m', $comment, $matches, PREG_OFFSET_CAPTURE)) {
			$comment = trim(substr($comment, 0, $matches[0][1]));
		}

		if ($comment !== '') {
			echo "\n" . $comment . "\n";
		}

		$options = $this->getGlobalOptions($class, $controller);
		if ($options !== array()) {
			echo "\nGLOBAL OPTIONS";
			echo "\n--------------\n\n";
			foreach ($options as $name => $description) {
				echo " --$name";
				if ($description != '') {
					echo ": $description\n";
				}
				echo "\n";
			}
		}

		$actions = $this->getActions($controller);
		if ($actions !== array()) {
			echo "\nSUB-COMMANDS";
			echo "\n------------\n\n";
			$prefix = $controller->getUniqueId();
			foreach ($actions as $action) {
				if ($controller->defaultAction === $action) {
					echo " * $prefix (default)\n";
				} else {
					echo " * $prefix/$action\n";
				}
			}
			echo "\n";
		}
	}

	/**
	 * Displays the detailed information of a command action.
	 * @param Controller $controller the controller instance
	 * @param string $actionID action ID
	 * @throws BadUsageException if the action does not exist
	 */
	protected function getActionHelp($controller, $actionID)
	{
		$action = $controller->createAction($actionID);
		if ($action === null) {
			throw new BadUsageException(Yii::t('yii', 'No help for unknown sub-command "{command}".', array(
				'{command}' => $controller->getUniqueId() . "/$actionID",
			)));
		}
		if ($action instanceof InlineAction) {
			$method = new \ReflectionMethod($controller, 'action' . $action->id);
		} else {
			$method = new \ReflectionMethod($action, 'run');
		}
		$comment = strtr(trim(preg_replace('/^\s*\**( |\t)?/m', '', trim($method->getDocComment(), '/'))), "\r", '');
		if (preg_match('/^\s*@\w+/m', $comment, $matches, PREG_OFFSET_CAPTURE)) {
			$meta = substr($comment, $matches[0][1]);
			$comment = trim(substr($comment, 0, $matches[0][1]));
		} else {
			$meta = '';
		}

		if ($comment !== '') {
			echo "\n" . $comment . "\n";
		}

		$options = $this->getOptions($method, $meta);
		if ($options !== array()) {
			echo "\nOPTIONS";
			echo "\n-------\n\n";
			foreach ($options as $name => $description) {
				echo " --$name";
				if ($description != '') {
					echo ": $description\n";
				}
			}
			echo "\n";
		}
	}

	/**
	 * @param \ReflectionMethod $method
	 * @param string $meta
	 * @return array
	 */
	protected function getOptions($method, $meta)
	{
		$params = $method->getParameters();
		$tags = preg_split('/^\s*@/m', $meta, -1, PREG_SPLIT_NO_EMPTY);
		$options = array();
		$count = 0;
		foreach ($tags as $tag) {
			$parts = preg_split('/\s+/', trim($tag), 2);
			if ($parts[0] === 'param' && isset($params[$count])) {
				$param = $params[$count];
				$comment = isset($parts[1]) ? $parts[1] : '';
				if (preg_match('/^([^\s]+)\s+(\$\w+\s+)?(.*)/s', $comment, $matches)) {
					$type = $matches[1];
					$doc = $matches[3];
				} else {
					$type = $comment;
					$doc = '';
				}
				$comment = $type === '' ? '' : ($type . ', ');
				if ($param->isDefaultValueAvailable()) {
					$value = $param->getDefaultValue();
					if (!is_array($value)) {
						$comment .= 'optional (defaults to ' . var_export($value, true) . ').';
					} else {
						$comment .= 'optional.';
					}
				} else {
					$comment .= 'required.';
				}
				if (trim($doc) !== '') {
					$comment .= "\n" . preg_replace("/^/m", "     ", $doc);
				}
				$options[$param->getName()] = $comment;
				$count++;
			}
		}
		if ($count < count($params)) {
			for ($i = $count; $i < count($params); ++$i) {
				$options[$params[$i]->getName()] = '';
			}
		}

		ksort($options);
		return $options;
	}

	/**
	 * @param \ReflectionClass $class
	 * @param Controller $controller
	 * @return array
	 */
	protected function getGlobalOptions($class, $controller)
	{
		$options = array();
		foreach ($class->getProperties() as $property) {
			if (!$property->isPublic() || $property->isStatic() || $property->getDeclaringClass()->getName() !== get_class($controller)) {
				continue;
			}
			$name = $property->getName();
			$comment = strtr(trim(preg_replace('/^\s*\**( |\t)?/m', '', trim($property->getDocComment(), '/'))), "\r", '');
			if (preg_match('/^\s*@\w+/m', $comment, $matches, PREG_OFFSET_CAPTURE)) {
				$meta = substr($comment, $matches[0][1]);
			} else {
				$meta = '';
			}
			$tags = preg_split('/^\s*@/m', $meta, -1, PREG_SPLIT_NO_EMPTY);
			foreach ($tags as $tag) {
				$parts = preg_split('/\s+/', trim($tag), 2);
				$comment = isset($parts[1]) ? $parts[1] : '';
				if ($parts[0] === 'var' || $parts[0] === 'property') {
					if (preg_match('/^([^\s]+)(\s+.*)?/s', $comment, $matches)) {
						$type = $matches[1];
						$doc = trim($matches[2]);
					} else {
						$type = $comment;
						$doc = '';
					}
					$comment = $type === '' ? '' : ($type);
					if (trim($doc) !== '') {
						$comment .= ', ' . preg_replace("/^/m", "", $doc);
					}
					$options[$name] = $comment;
					break;
				}
			}
			if (!isset($options[$name])) {
				$options[$name] = '';
			}
		}
		ksort($options);
		return $options;
	}
}