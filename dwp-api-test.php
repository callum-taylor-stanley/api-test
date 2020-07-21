<?php
	
	/*
		Very simplistic RESTful endpoint
	*/
	
	$method = $_SERVER['REQUEST_METHOD'];
	
	switch ($method) {
		case 'GET':
			handle_get($_SERVER['REQUEST_URI']);
		break;
		default:
			http_response_code(500);
				
			echo json_encode(array('error' => 'Method not supported.'));
		break;
	}
	
	function handle_get($uri) {
		$uri_array = explode('/', $uri);
		if (count($uri_array) > 2) {
			switch ($uri_array[2]) {
				case 'residents':
					if (!empty($uri_array[3]) && !empty($uri_array[4])) {
						get_residents_in_city_max_distance($uri_array[3], $uri_array[4]);
					} else {
						http_response_code(500);
					
						echo json_encode(
							array(
								'error' => 'Missing parameters.',
								'errorDetail' => 'The city and max distance(in miles) parameters must be supplied in format: /residents/{city}/{distance}'
							)
						);
					}
				break;
				
				default:
					http_response_code(500);
					
					echo json_encode(array('error' => 'URL not recognised.'));
				break;
			}
		}
	}
	
	function get_residents_in_city_max_distance($city, $max_distance) {
		/*
			This needs to be reworked
			We can either try to calculate the lat/long by using a GEOIP service/database and the city name
			We can also modify this function to take in the lat/long of the chosen city, but then we would need to check the city and lat/long match
			Or we pass in the latitude and longitude as part of the url parameters instead of the city
			This function only exists to "calculate" the latitude and longitude, we could technically move this into the function being called
		*/
		if (strtolower($city) === 'london') {
			$city_lat_long = (object) [
				'latitude' => '51.5074', 
				'longitude' => '0.1278'
			];
		}
		
		if (is_string($city) && is_numeric($max_distance)) {
			get_residents_by_city_max_distance_miles($city, $city_lat_long, $max_distance);
		} else {
			http_response_code(500);
					
			echo json_encode(array('error' => 'City parameter must be a string and max distance parameter must be numeric.'));
		}

	}
		
	class api_response {
		private $success_data;
		private $error_message;
		
		function set_success_data($success_data) {
			$this->success_data = $success_data;
		}
		
		function set_error_message($error_message) {
			$this->error_message = $error_message;
		}
		
		function get_success_data() {
			return $this->success_data;
		}
		
		function get_error_message() {
			return $this->error_message;
		}
		
		function has_error() {
			return !empty($this->error_message);
		}
	}

	function get_residents_by_city_max_distance_miles($city, $city_lat_long, $max_distance) {
		$residents_in_city = [];

		/**
			Firstly call the /users endpoint and fill the array with a list of all users
			This could be potentially very memory/network intensive on large lists, but we lack more fine grained filters
			If we had an endpoint that allowed us to retrieve just the latitude and longitude of all users that would help us greatly
		**/
		
		$url = 'https://bpdts-test-app.herokuapp.com/users';
		$api_response = call_bpdts_api($url);
		if (!$api_response->has_error()) {
			foreach ($api_response->get_success_data() as $resident) {
				array_push($residents_in_city, $resident);
			}
			
			/*
				Get residents who are in the city and update the existing array elements with these
			*/
			$city_residents = get_residents_by_city($city);
			foreach ($city_residents as $resident) {
				$matched_resident = array_search($resident->id, array_column($residents_in_city, 'id'));
				if ($matched_resident) {
					$residents_in_city[$matched_resident]->city = $city;
				} 
			}
			
			/*
				We won't use IP addresses for GEOIP lookup as they may be the results of proxies/VPN usage and can be very unreliable in terms of accuracy.
				Therefore for any users not returned from the /city/{city}/users search will go through a separate Longitude/Latitude check for the radius check	
			*/
			
			foreach ($residents_in_city as $key=>$resident) {
				if (!property_exists($resident, 'city')) {
					$user_lat_long = (object) [
						'latitude' => $resident->latitude, 
						'longitude' => $resident->longitude
					];
					$distance_from_city = get_distance_in_miles_between_points($city_lat_long, $user_lat_long);
					if ($distance_from_city > $max_distance) {
						unset($residents_in_city[$key]);
					}
				}
			}
			
			/*
				Final loop through the array and get the city for all the residents who are in the radius of the chosen city
			*/
			
			foreach ($residents_in_city as $key=>$resident) {
				if (!property_exists($resident, 'city')) {
					$resident_city = get_resident_city($resident);
					if (!empty($resident_city)) {
						$residents_in_city[$key]->city = $resident_city;
					}
				}
			}
			
			echo json_encode(array('result' => $residents_in_city));
		} else {
			http_response_code(500);
				
			echo json_encode(array('error' => $api_response->get_error_message));
		}
	}
	
	function get_residents_by_city($city) {
		if (!empty($city)) {
			$city = ucfirst($city); //API is case sensitive
			$url = 'https://bpdts-test-app.herokuapp.com/city/'.$city.'/users';
			$api_response = call_bpdts_api($url);
			if (!$api_response->has_error()) {
				return $api_response->get_success_data();
			}
		}
	}
		
	/**
		We have 2 ways of getting the user's city, either we pass the latitude and longitude into a GEOIP service 
		or we call the /user/{id} service to get the city.
		I am unable to find a non-paid GEOIP service for the first option, so will resort to the second
	**/
	function get_resident_city($user) {
		if ((array) $user) {
			$url = 'https://bpdts-test-app.herokuapp.com/user/'.$user->id;
			$api_response = call_bpdts_api($url);
			if (!$api_response->has_error()) {
				$api_result = $api_response->get_success_data();
				
				return $api_result->city;
			}

		}
	}
	
	/*
		Credits: https://thisinterestsme.com/php-haversine-formula-function/
	*/
	function get_distance_in_miles_between_points($location1, $location2) {
		if ((array)$location1 && (array)$location2) {
			$earth_radius = 3959;
		 
			$dLat = deg2rad($location2->latitude - $location1->latitude);
			$dLon = deg2rad($location2->longitude - $location1->longitude);
		 
			$a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($location1->latitude)) * cos(deg2rad($location2->latitude)) * sin($dLon/2) * sin($dLon/2);
			$c = 2 * asin(sqrt($a));
			$d = $earth_radius * $c;
		 
			return $d;
		}
	}

	function call_bpdts_api($url) {
		$response = new api_response;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //Obviously this would never be done in production environments
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //Obviously this would never be done in production environments
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
        $output = curl_exec($ch);

		if ($output === false) {
			$response->set_error_message(curl_error($ch));
		} else {
			$response->set_success_data(json_decode($output));
		}
		
		curl_close($ch);

		return $response;
	}

?>