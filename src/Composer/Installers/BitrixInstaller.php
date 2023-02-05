<?php

namespace Composer\Installers;

use Composer\Util\Filesystem;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use React\Promise\PromiseInterface;

/**
 * Installer for Bitrix Framework. Supported types of extensions:
 * - `bitrix-d7-module` — copy the module to directory `bitrix/modules/<vendor>.<name>`.
 *
 * You can set custom path to directory with Bitrix kernel in `composer.json`:
 *
 * ```json
 * {
 *      "extra": {
 *          "bitrix-dir": "s1/bitrix"
 *      }
 * }
 * ```
 *
 * @author Nik Samokhvalov <nik@samokhvalov.info>
 * @author Denis Kulichkin <onexhovia@gmail.com>
 */
 
 //  не удаляет предпоследний и раннее модули
 
class BitrixInstaller extends BaseInstaller
{
	private $module_settings = [];
	private $errors = [];
	
	/** @var array<string, string> */
	protected $locations = array(
		'd7-template' => '{$bitrix_dir}/modules/{$vendor}_{$name}/'
		// 'd7-module' => '{$bitrix_dir}/modules/{$vendor}_{$name}/'
	);

	/**
	 * @var string[] Storage for informations about duplicates at all the time of installation packages.
	 */
	private static $checkedDuplicates = array();
	
	public function GetModuleSettings() {
		return $this->module_settings;
	}

	public function inflectPackageVars(array $vars): array
	{
		/** @phpstan-ignore-next-line */
		if ($this->composer->getPackage()) {
			$extra = $this->composer->getPackage()->getExtra();

			if (isset($extra['bitrix-dir'])) {
				$vars['bitrix_dir'] = $extra['bitrix-dir'];
			}
			
			if ( isset($extra['installer-vendor']) ) {
				$vars['vendor'] = $extra['installer-vendor'];
			} else {
				throw new \Exception('installer-vendor должно быть заполнено');
			}

			if (isset($extra['modules'])) {
				$vars['name'] = NULL;

				foreach ( $extra['modules'] as $module ) {
					if ( !$module['current'] )
						continue;
						
					unset($module["current"]);
					
					$module['vendor'] = $vars['vendor'];
					
					$vars['name'] = $this->prepareName($module['name']);					

					if ( isset($module['site_path']) && !empty($module['site_path']) ) {
						$vars['site_path'] = $module['site_path'];
						$vars['bitrix_dir'] = $vars['site_path'] . '/local';
					}
					
					$this->module_settings = $module;
					$this->checkMain($vars);

					break;
				}

				if ( is_null($vars['name']) ) {
					array_push($this->errors, 'Current item not defined');
				}
			} elseif (!empty($extra['installer-name'])) {
				$vars['name'] = $extra['installer-name'];
			}
		}

		if ( isset($vars['site_path']) ) {
			$vars['bitrix_dir'] = $vars['site_path'] . '/local';
		} elseif ( !isset($vars['bitrix_dir']) ) {
			$vars['bitrix_dir'] = $extra['bitrix_dir'];
		} else {
			$vars['bitrix_dir'] = "www/local";
		}

		return parent::inflectPackageVars($vars);
	}
	
	protected function checkMain( array $vars ) : void {
		
		$main_dir = realpath($vars['site_path'] . "/local/modules/" . $vars['vendor'] . "_bx_main");
		$main_dir_log = $main_dir . "/log";
		
		if ( !is_dir($main_dir) && $vars['name'] != "bx_main" ) {
			throw new \Exception('bx_main не инсталирован композером');
		}
		
		if ( !is_dir($main_dir_log) && $vars['name'] != "bx_main" ) {
			throw new \Exception('bx_main не установлен в системе Bitrix');
		}
	}
	
	protected function prepareName( $name ) : string {
		$check = explode("/", $name);
		if ( count($check) > 1 )
			return strtolower($check[1]);
			
		return strtolower($name);
	}


	/**
	 * {@inheritdoc}
	 */
	protected function templatePath(string $path, array $vars = array()): string
	{
		$templatePath = parent::templatePath($path, $vars);
		$this->checkDuplicates($templatePath, $vars);

		return $templatePath;
	}

	/**
	 * Duplicates search packages.
	 *
	 * @param array<string, string> $vars
	 */
	protected function checkDuplicates(string $path, array $vars = array())
	{
		$packageType = substr($vars['type'], strlen('bitrix') + 1);

		$oldPath = str_replace(
			array('{$bitrix_dir}', '{$vendor}', '{$name}'),
			array($vars['bitrix_dir'], $vars['vendor'], $vars['name']),
			$this->locations[$packageType]
		);

		if (in_array($oldPath, static::$checkedDuplicates)) {
			return;
		}

		if ($oldPath !== $path && file_exists($oldPath) && $this->io->isInteractive()) {
			$this->io->writeError('    <error>Duplication of packages:</error>');
			$this->io->writeError('    <info>Package ' . $oldPath . ' will be called instead package ' . $path . '</info>');

			while (true) {
				switch ($this->io->ask('    <info>Delete ' . $oldPath . ' [y,n,?]?</info> ', '?')) {
					case 'y':
						$fs = new Filesystem();
						$fs->removeDirectory($oldPath);
						break 2;

					case 'n':
						break 2;

					case '?':
					default:
						$this->io->writeError(array(
							'    y - delete package ' . $oldPath . ' and to continue with the installation',
							'    n - don\'t delete and to continue with the installation',
						));
						$this->io->writeError('    ? - print help');
						break;
				}
			}
		}

		static::$checkedDuplicates[] = $oldPath;
	}
	
}