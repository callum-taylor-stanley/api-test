# api-test

	A very simple PHP script with a RESTful endpoint to get a list of users in a certain city and users within a certain radius
	
	Will return the following fields for each user; id, first_name, last_name, email, ip_address, longitude, latitude, city

	This page can be run on a LAMP/WAMP/MAMP/etc stack and called by hitting the /residents endpoint (e.g. residents/London/50).
	
	#TODO
	Swagger API
	Improve error handling
	Separate out functionality - specifically the RESTful endpoint parts
	Integrate proper GEOIP service
	Speed up executing by using curl_multi on the get_resident_city function.
