<?php
/**
 * @package EnvCanadaWeather
 * @version 0.1
 */
/*
Plugin Name: Environment Canada Weather widgets
Plugin URI: http://wordpress.org/extend/plugins/envcanadaweather/
Description: Add various bits of current weather data from Environment Canada's XML weather service. No forecast data yet, but you've got everything else. To find your city code, go to http://dd.weatheroffice.ec.gc.ca/citypage_weather/docs/site_list_provinces_en.html
Author: Paul d'Aoust
Version: 0.1
Author URI: http://heliosstudio.ca
*/

global $wpdb;
global $envcanadaweather_cacheTableName;
$envcanadaweather_cacheTableName = $wpdb->prefix."envcanadaweather_cache";

class EnvCanadaWeather {
	private static $weatherData = array ();
	private static $defaults = array (
		'province' => 'BC',
		'citycode' => 's0000772', // Penticton
		'updateInterval' => 3600 // one hour
	);
	private static $dbVersion = '0.1';
	private static $fields = array (
		'province' => 'string',
		'citycode' => 'string',
		'timestamp' => 'timestamp',
		'dateTime' => 'timestamp',
		'lat' => 'float',
		'lon' => 'float',
		'city' => 'string',
		'region' => 'string',
		'stationCode' => 'string',
		'stationLat' => 'float',
		'stationLon' => 'float',
		'observationDateTime' => 'timestamp',
		'condition' => 'string',
		'iconCode' => 'int',
		'temperature' => 'float',
		'dewpoint' => 'float',
		'pressure' => 'float',
		'pressureChange' => 'float',
		'pressureTendency' => 'string',
		'visibility' => 'float',
		'relativeHumidity' => 'float',
		'humidex' => 'float',
		'windSpeed' => 'float',
		'windGust' => 'float',
		'windDirection' => 'string',
		'windBearing' => 'int',
		'windChill' => 'float'
	);
	private static $cityCodes = array (
	);
	private static $iconCodes = array (
		'sunny',						// 00
		'mainlysunny',					// 01
		'partlycloudy',					// 02
		'mostlycloudy',					// 03
		'increasingcloud',				// 04
		'decreasingcloud',				// 05
		'lightrain',					// 06
		'lightrainsnow',				// 07
		'lightsnow',					// 08
		'thunderstorm',					// 09
		'cloudy',						// 10
		'lightrain',					// 11
		'rain',							// 12
		'heavyrain',					// 13
		'freezingrain',					// 14
		'rainsnow',						// 15
		'lightsnow',					// 16
		'snow',							// 17
		'heavysnow',					// 18
		'thunderstormprecipitation',	// 19
		'none', 'none', 'none',			// 20, 21, 22
		'haze',							// 23
		'fog',							// 24
		'driftingsnow',					// 25
		'crystals',						// 26
		'hail',							// 27
		'drizzle',						// 28
		'none',							// 29
		'clearnight',					// 30
		'mainlyclearnight',				// 31
		'partlycloudynight',			// 32
		'mostlycloudynight',			// 33
		'increasingcloudnight',			// 34
		'decreasingcloudnight',			// 35
		'lightrainnight',				// 36
		'lightrainsnownight',			// 37
		'lightsnownight',				// 38
		'thunderstormnight',			// 39
		'blowingsnow',					// 40
		'funnelcloud',					// 41
		'tornado',						// 42
		'none',							// 43
		'smoke',						// 44
		'dust'							// 45
	);

	public function install () {
		global $envcanadaweather_cacheTableName;
		$q = "CREATE TABLE $envcanadaweather_cacheTableName ("
			."	province CHAR(2),"
			."	citycode CHAR(8),"
			."	`timestamp` DATETIME,"
			."	dateTime DATETIME,"
			."	lat DECIMAL(5,2),"
			."	lon DECIMAL(5,2),"
			."	city VARCHAR(50),"
			."	region VARCHAR(255),"
			."	stationCode VARCHAR(50),"
			."	stationLat DECIMAL(5,2),"
			."	stationLon DECIMAL(5,2),"
			."	observationDateTime DATETIME,"
			."	`condition` VARCHAR(150),"
			."	iconCode SMALLINT(3) UNSIGNED,"
			."	temperature DECIMAL(5,2),"
			."	dewpoint DECIMAL(5,2),"
			."	pressure DECIMAL(5,2),"
			."	pressureChange DECIMAL(5,2),"
			."	pressureTendency VARCHAR(15),"
			."	visibility DECIMAL(5,2),"
			."	relativeHumidity DECIMAL(5,2),"
			."	humidex DECIMAL(5,2),"
			."	windSpeed DECIMAL(5,2),"
			."  windGust DECIMAL(5,2),"
			."	windDirection VARCHAR(3),"
			."	windBearing SMALLINT(3),"
			."	windChill DECIMAL(5,2),"
			."	PRIMARY KEY (province, citycode)"
			.")";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($q);
		add_option('envcanadaweather_dbversion', self::$dbVersion);
		if (!get_site_option('envcanadaweather_defaultprovince')) {
			add_option('envcanadaweather_defaultprovince', self::$defaults['province']);
		}
		if (!get_site_option('envcanadaweather_defaultcitycode')) {
			add_option('envcanadaweather_defaultcitycode', self::$defaults['citycode']);
		}
		if (!get_site_option('envcanadaweather_updateinterval')) {
			add_option('envcanadaweather_updateinterval', self::$defaults['updateInterval']);
		}
	}

	public function activate () {
		if (get_site_option('envcanadaweather_dbversion') != $GLOBALS['envcanadaweather_dbVersion']) {
			self::install();
		}
		self::$defaults['province'] = get_site_option('envcanadaweather_defaultprovince');
		self::$defaults['citycode'] = get_site_option('envcanadaweather_defaultcitycode');
		self::$defaults['updateinterval'] = get_site_option('envcanadaweather_updateinterval');
	}

	public function getData () {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			$attrs[$arg] = array_shift($args);
		}
		return self::_getData($attrs);
	}

	public function _getData ($attrs) {
		global $wpdb, $envcanadaweather_cacheTableName;
		if (!isset($attrs['province']) && !isset($attrs['citycode'])) {
			$province = self::$defaults['province'];
			$citycode = self::$defaults['citycode'];
		} else {
			$province = $attrs['province'];
			$citycode = $attrs['citycode'];
		}
		$cacheKey = $province.':'.$citycode;
		if (isset(self::$weatherData[$cacheKey])) {
			$weatherData = self::$weatherData[$cacheKey];
		} else {
			$q = "SELECT *, UNIX_TIMESTAMP(`timestamp`) AS `timestamp` FROM $envcanadaweather_cacheTableName WHERE province = '".mysql_real_escape_string($province)."' AND citycode = '".mysql_real_escape_string($citycode)."'";
			$weatherData = $wpdb->get_row($q, ARRAY_A);
			if ($weatherData) {
				self::$weatherData[$cacheKey] = $weatherData;
			}
		}
		if (!$weatherData || $weatherData['timestamp'] + self::$defaults['updateinterval'] < time()) {
			$weatherData = self::fetchData($province, $citycode);
		}
		if ($weatherData) {
			if (isset($attrs['format']) && $attrs['format']) {
				$format = $attrs['format'];
			} else {
				$format = self::$fields[$attrs['field']];
			}
			switch ($format) {
				case 'float':
					return (float) $weatherData[$attrs['field']];
				case 'int':
					return (int) $weatherData[$attrs['field']];
				case 'string':
					if ($attrs['field'] == 'iconCode') {
						return self::$iconCodes[(int) $weatherData['iconCode']];
					}
				default:
					return $weatherData[$attrs['field']];
			}
		}
	}

	public function printData () {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			$attrs[$arg] = array_shift($args);
		}
		return self::_printData($attrs);
	}

	public function _printData ($attrs) {
		echo self::_getData($attrs);
	}

	private function fetchData ($province, $citycode) {
		global $wpdb, $envcanadaweather_cacheTableName;
		$url = "http://dd.weatheroffice.ec.gc.ca/citypage_weather/xml/".urlencode($province)."/".urlencode($citycode)."_e.xml";
		$xml = file_get_contents($url);
		if (!$xml) {
			return false;
		}

		$weather = new SimpleXMLElement($xml);
		$current = $weather->currentConditions;
		$weatherData = array ();

		$weatherData['province'] = $province;
		$weatherData['citycode'] = $citycode;
		$weatherData['timestamp'] = time();
		foreach ($weather->dateTime as $dateTime) {
			if ($dateTime['zone'] == 'UTC') {
				$weatherData['dateTime'] = self::convertTimeData($dateTime->textSummary);
			}
		}
		$weatherData['lat'] = self::convertGeoData($weather->location->name['lat']);
		$weatherData['lon'] = self::convertGeoData($weather->location->name['lon']);
		$weatherData['city'] = (string) $weather->location->name;
		$weatherData['region'] = (string) $weather->location->region;
		$weatherData['stationCode'] = (string) $current->station['code'];
		$weatherData['stationLat'] = self::convertGeoData($current->station['lat']);
		$weatherData['stationLon'] = self::convertGeoData($current->station['lon']);
		foreach ($current->dateTime as $dateTime) {
			if ($dateTime['zone'] == 'UTC') {
				$weatherData['observationDateTime'] = self::convertTimeData($dateTime->textSummary);
			}
		}
		$weatherData['condition'] = strtolower($current->condition);
		$weatherData['iconCode'] = (int) $current->iconCode;
		$weatherData['temperature'] = (float) $current->temperature;
		$weatherData['dewpoint'] = (float) $current->dewpoint;
		$weatherData['pressure'] = (float) $current->pressure;
		$weatherData['pressureChange'] = (float) $current->pressure['change'];
		$weatherData['pressureTendency'] = (string) $current->pressure['tendency'];
		$weatherData['visibility'] = (float) $current->visibility;
		$weatherData['relativeHumidity'] = (float) $current->relativeHumidity;
		$weatherData['windSpeed'] = (float) $current->wind->speed;
		$weatherData['windGust'] = (float) $current->wind->gust;
		$weatherData['windDirection'] = (string) $current->wind->direction;
		$weatherData['windBearing'] = (float) $current->wind->bearing;

		$cacheKey = $province.':'.$citycode;
		self::$weatherData[$cacheKey] = $weatherData;

		$q = "REPLACE INTO $envcanadaweather_cacheTableName SET ";
		$qArray = array();

		foreach (self::$fields as $fieldName => $fieldType) {
			$thisQ = "`$fieldName` = ";
			if (is_null($weatherData[$fieldName])) {
				$thisQ .= 'NULL';
			} else {
				switch ($fieldType) {
					case 'string':
						$thisQ .= "'".mysql_real_escape_string($weatherData[$fieldName])."'";
						break;
					case 'float':
					case 'int':
						$thisQ .= $weatherData[$fieldName];
						break;
					case 'timestamp':
						$thisQ .= 'FROM_UNIXTIME('.$weatherData[$fieldName].')';
				}
			}
			array_push($qArray, $thisQ);
		}

		$q .= implode(',', $qArray);
		$wpdb->query($q);

		return $weatherData;
	}

	private function convertGeoData ($coord) {
		$coord = str_split($coord);
		$direction = strtoupper(array_pop($coord));
		$coord = (float) implode('', $coord);
		if (in_array(direction, array('N', 'E'))) {
			return $coord;
		} else {
			return 0 - $coord;
		}
	}

	private function convertTimeData ($datetime) {
		return strtotime(str_replace('at ', '', $datetime));
	}
}

register_activation_hook(__FILE__, array('EnvCanadaWeather', 'activate'));
add_action('plugins_loaded', array('EnvCanadaWeather', 'activate'));
add_shortcode('envcanadaweather_data', array('EnvCanadaWeather', '_getData'));
?>
