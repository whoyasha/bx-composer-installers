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
	
	/** @var array<string, string> */
	protected $locations = array(
		// 'module' => '{$bitrix_dir}/modules/{$vendor}_{$name}/',
		// 'd7-module' => '{$bitrix_dir}/modules/{$vendor}_{$name}/',
		'd7-modules' => '{$bitrix_dir}/modules/{$vendor}_{$name}/'
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
			
			$vars['name'] = NULL;

			if ( isset($extra['installer-vendor']) ) {
				$vars['vendor'] = $extra['installer-vendor'];
			} else {
				throw new \Exception('installer-vendor должно быть заполнено');
			}
			
			if ( isset($extra['']) ) {
				$vars['bx-skeleton-main'] = $extra['bx-skeleton-main'];
			}
			
			if (isset($extra['bx-skeleton-modules']) && count($extra['bx-skeleton-modules']) > 0) {

				foreach ( $extra['bx-skeleton-modules'] as $module ) {
					
					if ( !$module['current'] ) {
						continue;
					}
					
					unset($module["current"]);

					$prep_name = $this->prepareName($module['name']);	

					$vars['name'] = $prep_name["name"];
					if ( array_key_exists("vendor", $prep_name) )
						$vars['vendor'] = $prep_name["vendor"];
					
					$module['vendor'] = $vars['vendor'];
					
					$module['is_main'] = false;
					if ( isset($vars['bx-skeleton-main']) && $vars['name'] == $vars['bx-skeleton-main']) {
						$module['is_main'] = true;
						
						$this->io->writeError('    <error>Module ' . $vars['name'] . ' is main</error>');
					}
					
					$vars['bitrix_dir'] = $extra['bitrix_dir'];		
					if ( isset($module['site_path']) && !empty($module['site_path']) ) {
						$vars['site_path'] = $module['site_path'];
						$vars['bitrix_dir'] = $vars['site_path'] . '/local';
					}
					
					if ( isset($vars['site_path']) ) {
						$vars['bitrix_dir'] = $vars['site_path'] . '/local';
					} else {
						$vars['bitrix_dir'] = "www/local";
					}

					$module["has_main"] = $this->hasMain($vars);
					$this->module_settings = $module;

					break;
				}

				if ( is_null($vars['name']) ) {
					$this->module_settings = NULL;
					// array_push($this->errors, 'Not module to install found');
				}
			}
		}

		return parent::inflectPackageVars($vars);
	}
	
	protected function hasMain( array $vars ) {
		
		$main_dir = realpath($vars['bitrix_dir'] . "/modules/" . $vars['vendor'] . "_bx_main");
		$main_dir_log = $main_dir . "/log";
		
		return is_dir($main_dir);
	}
	
	protected function prepareName( $name ) : array {
		$check = explode("/", $name);
		
		if ( count($check) > 1 ) {
			$name = "";
			$check_1 = explode(".", $check[1]);
			
			if ( count($check_1) > 1 ) {
				$name = $check_1[0] . "_" . $check_1[1];
			} else {
				$name = $check[1];
			}
			
			return ["vendor" => $check[0], "name" => strtolower($name)];
		}
			
		return ["name" => strtolower($name)];
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
