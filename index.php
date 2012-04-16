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
	/* $weatherData is just a hashed cache; keys are city codes and
	   values are an array of fields */
	private static $weatherData = array ();
	private static $defaults = array (
		'province' => 'BC',
		'citycode' => 's0000772', // Penticton
		'updateInterval' => 3600 // one hour
	);
	private static $dbVersion = '0.1';
	/* acceptable fields and type hint(s) for each; the first in the
	 * array is the default hint. Note: for time data, 'string' is an
	 * alias for 'datetime' */
	private static $fields = array (
		'province' => array('string'),
		'citycode' => array('string'),
		'timestamp' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'dateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'lat' => array('float', 'int'),
		'lon' => array('float', 'int'),
		'city' => array('string'),
		'region' => array('string'),
		'stationCode' => array('string'),
		'stationLat' => array('float', 'int'),
		'stationLon' => array('float', 'int'),
		'observationDateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'condition' => array('string'),
		'iconCode' => array('int', 'string'),
		'temperature' => array('float', 'int'),
		'dewpoint' => array('float', 'int'),
		'pressure' => array('float', 'int'),
		'pressureChange' => array('float', 'int'),
		'pressureTendency' => array('string'),
		'visibility' => array('float', 'int'),
		'relativeHumidity' => array('float', 'int'),
		'humidex' => array('float', 'int'),
		'windSpeed' => array('float', 'int'),
		'windGust' => array('float', 'int'),
		'windDirection' => array('string'),
		'windBearing' => array('int'),
		'windChill' => array('float', 'int')
	);
	private static $cityCodes = array (
	);
	/* a list of short descriptions mapped to icon codes; useful for
	   CSS hooks */
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

	/* set up cache table in database, and insert default city, prov,
	 * and update interval into wp_config table if they don't exist */
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
		dbDelta($q); // WordPress' built-in database upgrade function
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
		// check installed table against this plugin's version
		if (get_site_option('envcanadaweather_dbversion') != self::$dbVersion) {
			self::install();
		}
		/* get location and update interval defaults for when none are
		 * specified by shortcode */
		self::$defaults['province'] = get_site_option('envcanadaweather_defaultprovince');
		self::$defaults['citycode'] = get_site_option('envcanadaweather_defaultcitycode');
		self::$defaults['updateinterval'] = get_site_option('envcanadaweather_updateinterval');
	}

	/* function for using in a template; convenience function for
	 * _getData() */
	public function getData (/* $field [,$format, [$citycode, $province]] */) {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			if (count($args)) {
				$attrs[$arg] = array_shift($args);
			}
		}
		return self::_getData($attrs);
	}

	/* return a certain piece of weather information; conforms to WP's
	 * shortcode interface by accepting an array of arguments:
	 *
	 * 		'field': the piece of information to return (see above
	 * 		$fields property for a list of valid fields)
	 *
	 * 		'format': an optional argument that parses the field in an
	 * 		alternate format to the one specified in $fields. Possible
	 * 		values:
	 * 			'float': convert a value to a float
	 * 			'int': make sure the value is not a float; rounds to
	 * 			the nearest integer
	 * 			'string': currently for icon codes only; converts an
	 * 			icon code to its corresponding hint (see above
	 * 			$iconCodes property)
	 *
	 * 		'citycode': an optional city code; if not specified, uses
	 * 		envcanadaweather_defaultcitycode from the wp_options table
	 *
	 * 		'province': required if 'citycode' is specified; helps us
	 * 		find the correct folder on Environment Canada's server.
	 * 		Really, this should be automatic, but it isn't yet :) */

	public function _getData ($attrs) {
		global $wpdb, $envcanadaweather_cacheTableName;
		// get defaults for city
		if (!isset($attrs['province']) && !isset($attrs['citycode'])) {
			$province = self::$defaults['province'];
			$citycode = self::$defaults['citycode'];
		} else {
			$province = $attrs['province'];
			$citycode = $attrs['citycode'];
		}
		// create a key for the database (and self::$weatherData) cache
		$cacheKey = $province.':'.$citycode;
		// is the data cached in memory?
		if (isset(self::$weatherData[$cacheKey])) {
			$weatherData = self::$weatherData[$cacheKey];
		} else {
			// if it's not in memory, is it stored in the database?
			$q = "SELECT *, UNIX_TIMESTAMP(`timestamp`) AS `timestamp` FROM $envcanadaweather_cacheTableName WHERE province = '".mysql_real_escape_string($province)."' AND citycode = '".mysql_real_escape_string($citycode)."'";
			$weatherData = $wpdb->get_row($q, ARRAY_A);
			if ($weatherData) {
				self::$weatherData[$cacheKey] = $weatherData;
			}
		}
		/* if not, or if the data is stale, let's prime both memory and
		 * db cache with fresh info */
		if (!$weatherData || $weatherData['timestamp'] + self::$defaults['updateinterval'] < time()) {
			$weatherData = self::fetchData($province, $citycode);
		}
		if ($weatherData) {
			// get the proper format; fall over to default if invalid
			$format = null;
			if (isset($attrs['format']) && $attrs['format']) {
				if (in_array($attrs['format'], self::$fields[$attrs['field']])) {
					$format = $attrs['format'];
				}
			}
			if (is_null($format)) {
				$format = self::$fields[$attrs['field']][0];
			}
			// and then output the data!
			switch ($format) {
				case 'float':
					return (float) $weatherData[$attrs['field']];
				case 'int':
				case 'timestamp':
					/* still not decided on whether this should be round
					 * or floor -- round is more accurate, but it looks
					 * weird when you've got 29.6°C in one spot and 30°C
					 * in another */
					return round($weatherData[$attrs['field']]);
				case 'string':
					/* get the icon code hint instead of the icon code
					 * itself */
					if ($attrs['field'] == 'iconCode') {
						return self::$iconCodes[(int) $weatherData['iconCode']];
					}
				// falls over into timestamp formatters
				case 'date':
				case 'time':
				case 'datetime':
					if (in_array($attrs['field'], array('timestamp', 'dateTime', 'observationDateTime'))) {
						/* construct the date based on whether the date,
						 * time, or both ('string') were asked for */
						$dateFormat = array();
						if (in_array($format, array('date', 'string'))) {
							$dateFormat[] = get_site_option('date_format');
						}
						if (in_array($format, array('time', 'string'))) {
							$dateFormat[] = get_site_option('time_format');
						}
						$dateFormat = implode(' ', $dateFormat);
						return date($dateFormat);
					}
				/* falls over into just spewing out the raw data for all
				 * other string-based data */
				default:
					return (string) $weatherData[$attrs['field']];
			}
		}
	}

	// convenience function; prints out the result of getData();
	public function printData () {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			if (count($args)) {
				$attrs[$arg] = array_shift($args);
			}
		}
		return self::_printData($attrs);
	}

	public function _printData ($attrs) {
		echo self::_getData($attrs);
	}

	/* charge up the cache with data; should only be called when cache
	 * is either expired or empty for a given city */
	private function fetchData ($province, $citycode) {
		global $wpdb, $envcanadaweather_cacheTableName;
		$url = "http://dd.weatheroffice.ec.gc.ca/citypage_weather/xml/".urlencode($province)."/".urlencode($citycode)."_e.xml";
		$xml = file_get_contents($url);
		if (!$xml) {
			return false;
		}

		// get the most important bit; the currentConditions
		$weather = new SimpleXMLElement($xml);
		$current = $weather->currentConditions;
		$weatherData = array ();

		// get all the useful parts from the XML
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

		// add data to memory cache
		$cacheKey = $province.':'.$citycode;
		self::$weatherData[$cacheKey] = $weatherData;

		// add data to database cache
		$q = "REPLACE INTO $envcanadaweather_cacheTableName SET ";
		$qArray = array();

		foreach (self::$fields as $fieldName => $fieldType) {
			$thisQ = "`$fieldName` = ";
			if (is_null($weatherData[$fieldName])) {
				$thisQ .= 'NULL';
			} else {
				switch ($fieldType[0]) {
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

	/* converts unsigned coordinates (with 'N', 'S', 'E', and 'W') into
	 * more standard signed coordinates */
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

	/* converts time data into UNIX timestamp */
	private function convertTimeData ($datetime) {
		return strtotime(str_replace('at ', '', $datetime));
	}
}

register_activation_hook(__FILE__, array('EnvCanadaWeather', 'activate'));
add_action('plugins_loaded', array('EnvCanadaWeather', 'activate'));
add_shortcode('envcanadaweather_data', array('EnvCanadaWeather', '_getData'));
?>
