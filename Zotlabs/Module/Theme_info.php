<?php

namespace Zotlabs\Module;


class Theme_info extends \Zotlabs\Web\Controller {

	function get() {
		$theme = argv(1);
		if(! $theme)
			killme();

		$theme_config = "";
		if(($themeconfigfile = $this->get_theme_config_file($theme)) != null){
			require_once($themeconfigfile);
			if(class_exists(ucfirst($theme) . 'Config')) {
				$clsname = ucfirst($theme) . 'Config';
				$th_config = new $clsname();
				$schemas = $th_config->get_schemas();
			}
			$theme_config = theme_content($a);
		}
		$info = get_theme_info($theme);
		if($info) {
			// unfortunately there will be no translation for this string
			$desc    = $info['description'];
			$version = $info['version'];
			$credits = $info['credits'];
		}
		else {
			$desc = '';
			$version = '';
			$credits = '';
		}

		$ret = [ 
			'theme' => $theme, 
			'img' => get_theme_screenshot($theme), 
			'desc' => $desc, 
			'version' => $version, 
			'credits' => $credits, 
			'schemas' => $schemas,
			'config' => $theme_config
		];
		json_return_and_die($ret);
		
	}


	function get_theme_config_file($theme){

		$base_theme = \App::$theme_info['extends'];
	
		if (file_exists("view/theme/$theme/php/config.php")){
			return "view/theme/$theme/php/config.php";
		} 
		if (file_exists("view/theme/$base_theme/php/config.php")){
			return "view/theme/$base_theme/php/config.php";
		}
		return null;
	}


}